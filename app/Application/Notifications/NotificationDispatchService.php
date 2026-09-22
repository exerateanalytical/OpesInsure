<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Notifications\Adapters\NotificationAdapterRegistry;
use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use App\Models\PartyContact;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The missing "actually send it" step: NotificationController::queue() only
 * ever inserted a QUEUED row and fired an outbox event nothing consumed.
 * This resolves the real destination (party_contacts, never the stored
 * destination_hash — that's one-way), renders the approved template, calls
 * the channel adapter, and updates the existing status machine that
 * NotificationDeliveryService::retry()/cancel() already implement.
 */
final class NotificationDispatchService
{
    public function __construct(
        private NotificationAdapterRegistry $adapters,
        private AuditWriter $audit,
        private OutboxWriter $outbox,
    ) {
    }

    public function dispatch(NotificationDelivery $delivery): NotificationDelivery
    {
        $claimed = DB::table('notification_deliveries')
            ->where('id', $delivery->id)
            ->where('status', 'QUEUED')
            ->update(['status' => 'SENDING', 'updated_at' => now()]);

        if (! $claimed) {
            return $delivery->refresh();
        }

        $delivery = $delivery->refresh();

        try {
            [$destination, $subject, $body] = $this->resolve($delivery);

            $adapter = $this->adapters->for($delivery->channel);
            $result = $adapter->send($destination, $subject, $body, $delivery->idempotency_key ?? $delivery->id);

            $attemptNumber = $delivery->attempts + 1;

            $delivery->update([
                'status' => 'SENT',
                'provider' => $result->provider,
                'provider_reference' => $result->providerReference,
                'sent_at' => now(),
            ]);

            DB::table('notification_attempts')->insert([
                'id' => (string) Str::uuid(),
                'notification_delivery_id' => $delivery->id,
                'attempt_number' => $attemptNumber,
                'status' => 'SENT',
                'provider_reference' => $result->providerReference,
                'response' => json_encode($result->safeResponse),
                'attempted_at' => now(),
            ]);

            $this->audit->record('notification.sent', 'notification_delivery', $delivery->id, ['channel' => $delivery->channel, 'provider' => $result->provider]);
            $this->outbox->record('notification.delivery.sent', 'notification_delivery', $delivery->id, ['delivery_id' => $delivery->id]);

            return $delivery->refresh();
        } catch (Throwable $e) {
            return $this->recordFailure($delivery, $e);
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string} [destination, rendered subject, rendered body]
     */
    private function resolve(NotificationDelivery $delivery): array
    {
        $template = NotificationTemplate::findOrFail($delivery->template_id);
        $destination = $this->resolveDestination($delivery);

        if (hash('sha256', mb_strtolower(trim($destination))) !== $delivery->destination_hash) {
            throw new DomainException('Resolved destination no longer matches the destination this notification was queued for.');
        }

        $variables = $delivery->payload ?? [];

        return [$destination, $this->render((string) $template->subject, $variables), $this->render((string) $template->body, $variables)];
    }

    private function resolveDestination(NotificationDelivery $delivery): string
    {
        if ($delivery->party_id === null) {
            throw new DomainException('Cannot resolve a destination for a notification with no party.');
        }

        $type = $delivery->channel === 'EMAIL' ? 'EMAIL' : 'PHONE';

        $contact = PartyContact::where('party_id', $delivery->party_id)->where('type', $type)->where('is_primary', true)->first();

        if (! $contact) {
            throw new DomainException("Party has no primary {$type} contact to deliver this notification to.");
        }

        return $contact->normalized_value;
    }

    private function render(string $template, array $variables): string
    {
        $replacements = [];

        foreach ($variables as $key => $value) {
            $replacements['{{'.$key.'}}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }

    /**
     * Mirrors NotificationDeliveryService::retry()'s own backoff formula
     * intentionally (min(60, 2**attempt) minutes) but is self-contained
     * rather than calling into it: retry() writes its own notification_attempts
     * row assuming $delivery->attempts doesn't yet reflect this cycle, which
     * would collide with the row this method already writes for the same
     * attempt_number. retry() remains the correct, unchanged path for an
     * operator manually re-queuing an already-FAILED delivery.
     */
    private function recordFailure(NotificationDelivery $delivery, Throwable $e): NotificationDelivery
    {
        return DB::transaction(function () use ($delivery, $e) {
            $locked = NotificationDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $attemptNumber = $locked->attempts + 1;

            DB::table('notification_attempts')->insert([
                'id' => (string) Str::uuid(),
                'notification_delivery_id' => $locked->id,
                'attempt_number' => $attemptNumber,
                'status' => 'FAILED',
                'failure_code' => 'CHANNEL_SEND_FAILED',
                'response' => json_encode(['error' => mb_substr($e->getMessage(), 0, 500)]),
                'attempted_at' => now(),
            ]);

            if ($attemptNumber >= $locked->max_attempts) {
                $locked->update(['status' => 'DEAD_LETTERED', 'attempts' => $attemptNumber, 'failure_reason' => $e->getMessage(), 'failure_code' => 'CHANNEL_SEND_FAILED']);
                $this->audit->record('notification.dead_lettered', 'notification_delivery', $locked->id, ['attempts' => $attemptNumber]);
            } else {
                $locked->update([
                    'status' => 'QUEUED',
                    'attempts' => $attemptNumber,
                    'next_attempt_at' => now()->addMinutes(min(60, 2 ** $attemptNumber)),
                    'failure_reason' => $e->getMessage(),
                    'failure_code' => 'CHANNEL_SEND_FAILED',
                ]);
                $this->audit->record('notification.retry.scheduled', 'notification_delivery', $locked->id, ['attempt' => $attemptNumber]);
            }

            return $locked->refresh();
        });
    }
}
