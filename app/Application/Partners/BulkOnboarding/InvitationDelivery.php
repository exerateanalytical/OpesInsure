<?php

declare(strict_types=1);

namespace App\Application\Partners\BulkOnboarding;

use App\Application\Notifications\Adapters\NotificationAdapterRegistry;
use App\Application\Notifications\Otp\OtpDeliveryService;
use App\Application\Notifications\Sms\SmsDeliveryException;
use App\Application\Notifications\Sms\SmsGateway;
use App\Models\Tenant;
use Throwable;

/**
 * Sends a bulk-onboarded broker admin their invitation code: SMS through the admin-configured SmsGateway (falling back
 * to the OTP drivers — ETECH — when no gateway provider is configured) and email through the SMTP adapter.
 * Never throws: a delivery failure is reported per row, the undelivered code stays available in the result report.
 *
 * @return array<string, string> channel => SENT | FAILED | SKIPPED
 */
final class InvitationDelivery
{
    public function __construct(
        private readonly SmsGateway $sms,
        private readonly OtpDeliveryService $otp,
        private readonly NotificationAdapterRegistry $adapters,
    ) {}

    public function send(array $row, Tenant $tenant, string $token): array
    {
        $params = ['name' => $row['admin_name'], 'broker' => $tenant->trade_name ?: $tenant->legal_name, 'code' => $token,
            'url' => rtrim((string) config('app.url'), '/'), 'days' => (int) (BrokerOnboardingTarget::INVITATION_TTL_HOURS / 24)];
        $locale = $row['locale'] ?? 'fr';
        $out = [];

        if ($row['admin_phone']) {
            $message = __('bulk_onboarding.invite.sms', $params, $locale);
            try {
                $this->sms->send($row['admin_phone'], $message, 'INVITATION');
                $out['SMS'] = 'SENT';
            } catch (SmsDeliveryException $e) {
                $out['SMS'] = $e->status === 'CONFIG_REQUIRED' && $this->otp->send($row['admin_phone'], substr($token, 0, 6), $message, 'sms') !== null ? 'SENT' : 'FAILED';
            } catch (Throwable $e) {
                report($e);
                $out['SMS'] = 'FAILED';
            }
        }
        if ($row['admin_email']) {
            try {
                $this->adapters->for('EMAIL')->send($row['admin_email'], __('bulk_onboarding.invite.email_subject', $params, $locale),
                    __('bulk_onboarding.invite.email_body', $params, $locale), 'broker-invite-'.hash('sha256', $token));
                $out['EMAIL'] = 'SENT';
            } catch (Throwable $e) {
                report($e);
                $out['EMAIL'] = 'FAILED';
            }
        }

        return $out;
    }
}
