<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Notifications\Adapters\TwilioSmsAdapter;
use App\Application\Notifications\Push\SendPushNotificationJob;
use App\Mail\NotificationMail;
use Illuminate\Support\Facades\Mail;
use App\Models\PartyContact;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one place lifecycle events become customer-visible: an inbox row
 * (UserNotification, what app/notifications renders), a queued push to the
 * user's registered Expo/FCM tokens, and — only when SMS is configured and
 * either the caller asks for it or the user has no push token — an SMS via
 * the existing Twilio notification adapter.
 *
 * Never throws: a notification failure must not roll back or fail the
 * payment/issuance/claim transition that produced it. Failures are logged.
 *
 * Categories map onto the user's notification_preferences
 * (payments / claims / renewals); "security" and "policy" always deliver.
 */
final class CustomerNotifier
{
    private const CATEGORY_PREFERENCE = ['PAYMENT' => 'payments', 'CLAIM' => 'claims', 'RENEWAL' => 'renewals'];

    /** @return int number of users notified */
    public function toParty(?string $partyId, ?string $tenantId, string $type, string $title, string $body, string $severity = 'INFO', ?string $path = null, bool $forceSms = false, ?string $code = null, array $params = []): int
    {
        if (! $partyId) {
            return 0;
        }

        try {
            $users = $this->usersForParty($partyId);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }

        foreach ($users as $user) {
            $this->toUser($user, $tenantId, $type, $title, $body, $severity, $path, $forceSms, $code, $params);
        }

        return $users->count();
    }

    /**
     * $code/$params: a NotificationCatalog message (use NotificationCatalog::message() to fill
     * title, body, code and params). The inbox row keeps the English text plus the code; push
     * and SMS go out in the user's language (users.locale).
     */
    public function toUser(User $user, ?string $tenantId, string $type, string $title, string $body, string $severity = 'INFO', ?string $path = null, bool $forceSms = false, ?string $code = null, array $params = []): ?UserNotification
    {
        try {
            $notification = UserNotification::notify($user, $type, $title, $body, $severity, $path, $tenantId, $code, $params);
            if (! $notification->wasRecentlyCreated) {
                return $notification; // de-duplicated: already told this user
            }

            $prefs = array_merge(['push' => true, 'sms' => true, 'payments' => true, 'claims' => true, 'renewals' => true], $user->notification_preferences ?? []);
            $categoryKey = self::CATEGORY_PREFERENCE[$type] ?? null;
            if ($categoryKey !== null && ! $prefs[$categoryKey]) {
                return $notification; // inbox only — the user muted this category
            }

            $local = $code ? NotificationCatalog::render($code, $params, NotificationCatalog::locale($user->locale)) : null;
            [$title, $body] = [$local['title'] ?? $title, $local['body'] ?? $body];

            $hasPushToken = DB::table('user_push_tokens')->where('user_id', $user->id)->exists();
            if ($prefs['push'] && $hasPushToken) {
                SendPushNotificationJob::dispatch($user->id, $title, $body, array_filter(['path' => $path, 'type' => $type, 'notification_id' => $notification->id]))->afterCommit();
            }

            // S8: channel templates (notification_templates, tenant override > platform) when one exists for the code.
            $locale = NotificationCatalog::locale($user->locale);
            $templates = app(NotificationTemplateRenderer::class);
            if ($prefs['sms'] && ($forceSms || ! $hasPushToken) && $this->channelAllowed($user, 'SMS')) {
                $sms = $templates->render($code, 'SMS', $locale, $tenantId, $params, $user);
                $this->sms($user, $title, $body, $notification->id, $sms['body'] ?? null);
            }
            if (($prefs['email'] ?? true) && $user->email && $this->channelAllowed($user, 'EMAIL')
                && ($email = $templates->render($code, 'EMAIL', $locale, $tenantId, $params, $user))) {
                Mail::to($user->email)->queue((new NotificationMail((string) ($email['subject'] ?? $title), $email['body']))->afterCommit());
            }

            return $notification;
        } catch (Throwable $e) {
            Log::warning('customer_notification.failed', ['user_id' => $user->id, 'type' => $type, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public static function smsConfigured(): bool
    {
        return (string) config('services.twilio.account_sid') !== ''
            && (string) config('services.twilio.auth_token') !== ''
            && (string) config('services.twilio.sms_from') !== '';
    }

    /** communication_preferences (party-level, TRANSACTIONAL purpose): an explicit opt-out of a channel is honoured. */
    private function channelAllowed(User $user, string $channel): bool
    {
        if (! $user->party_id) {
            return true;
        }

        return DB::table('communication_preferences')->where(['party_id' => $user->party_id, 'purpose' => 'TRANSACTIONAL', 'channel' => $channel])->value('enabled') !== false;
    }

    private function sms(User $user, string $title, string $body, string $notificationId, ?string $templated = null): void
    {
        if (! self::smsConfigured() || ! $user->phone_e164) {
            return;
        }

        try {
            app(TwilioSmsAdapter::class)->send($user->phone_e164, $title, $templated ?? Str::limit("{$title}: {$body}", 300), 'notif-'.$notificationId);
        } catch (Throwable $e) {
            Log::warning('customer_notification.sms_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    /** users.party_id is authoritative; a phone match covers users not yet backfilled (see PartyResolver). */
    private function usersForParty(string $partyId): Collection
    {
        $phones = PartyContact::where('party_id', $partyId)->where('type', 'PHONE')->pluck('normalized_value');

        return User::where('status', '!=', 'DISABLED')
            ->where(fn ($q) => $q->where('party_id', $partyId)->orWhere(fn ($x) => $x->whereNull('party_id')->whereIn('phone_e164', $phones)))
            ->get();
    }
}
