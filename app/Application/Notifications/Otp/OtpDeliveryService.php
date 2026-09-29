<?php

declare(strict_types=1);

namespace App\Application\Notifications\Otp;

use App\Application\Notifications\Sms\SmsDeliveryException;
use App\Application\Notifications\Sms\SmsGateway;
use App\Application\Settings\PlatformSettings;
use App\Models\OtpDelivery;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a one-time code through the first driver that works, walking the
 * admin-configured channel priority (e.g. whatsapp, sms) and, within each
 * channel, the provider priority (e.g. etech, twilio). A caller-preferred
 * channel is tried first. Every attempt is recorded in otp_deliveries (the
 * ETECH DLR webhook updates those rows). If nothing succeeds it logs
 * critical and returns null; it never throws, so API responses keep one shape.
 */
final class OtpDeliveryService
{
    public function __construct(private PlatformSettings $settings)
    {
    }

    /** @return array{provider: string, channel: string}|null the driver that accepted the message */
    public function send(string $phoneE164, string $code, string $message, ?string $preferredChannel = null, ?string $challengeId = null): ?array
    {
        $channels = $this->settings->otpChannelPriority();
        if ($preferredChannel !== null && in_array($preferredChannel, ['sms', 'whatsapp'], true)) {
            $channels = array_values(array_unique([$preferredChannel, ...$channels]));
        }

        $failures = [];

        foreach ($channels as $channel) {
            // S14: the admin-configured SMS providers (SmsGateway: primary → fallback) come before the legacy drivers.
            foreach ($channel === 'sms' ? ['gateway', ...$this->settings->otpProviderPriority()] : $this->settings->otpProviderPriority() as $provider) {
                $driver = $this->driver($provider, $channel);

                if (! $driver->isConfigured()) {
                    continue;
                }

                try {
                    $reference = $driver->send($phoneE164, $code, $message);
                    $this->record($driver, $phoneE164, $challengeId, 'SENT', $reference, null);

                    return ['provider' => $provider, 'channel' => $channel];
                } catch (SmsDeliveryException $e) {
                    $this->record($driver, $phoneE164, $challengeId, 'FAILED', null, $e->getMessage());
                    if ($e->status === 'RATE_LIMITED') {
                        // Per-number limit: no other provider may be used to get around it.
                        Log::warning('otp.rate_limited');

                        return null;
                    }
                    $failures[] = "{$provider}/{$channel}: ".$e->getMessage();
                } catch (Throwable $e) {
                    $failures[] = "{$provider}/{$channel}: ".$e->getMessage();
                    $this->record($driver, $phoneE164, $challengeId, 'FAILED', null, $e->getMessage());
                }
            }
        }

        Log::critical('otp.delivery_failed', [
            'reason' => $failures === [] ? 'No OTP provider is configured (admin Platform settings or .env).' : 'Every configured OTP provider failed.',
            'failures' => $failures,
        ]);

        return null;
    }

    /** CONFIG_REQUIRED when no SMS/WhatsApp driver (admin SMS providers or legacy settings) could send a code. */
    public function status(): string
    {
        foreach ($this->settings->otpChannelPriority() as $channel) {
            foreach ($channel === 'sms' ? ['gateway', ...$this->settings->otpProviderPriority()] : $this->settings->otpProviderPriority() as $provider) {
                if ($this->driver($provider, $channel)->isConfigured()) {
                    return 'CONFIGURED';
                }
            }
        }

        return 'CONFIG_REQUIRED';
    }

    public function driver(string $provider, string $channel): OtpChannel
    {
        return match ("{$provider}:{$channel}") {
            'gateway:sms' => new GatewaySmsDriver(app(SmsGateway::class)),
            'etech:sms' => new EtechSmsDriver($this->settings),
            'etech:whatsapp' => new EtechWhatsAppDriver($this->settings),
            'twilio:sms' => new TwilioSmsDriver($this->settings),
            'twilio:whatsapp' => new TwilioWhatsAppDriver($this->settings),
        };
    }

    private function record(OtpChannel $driver, string $phone, ?string $challengeId, string $status, ?string $reference, ?string $failure): void
    {
        try {
            OtpDelivery::create([
                'verification_challenge_id' => $challengeId,
                'provider' => $driver->provider(),
                'channel' => $driver->channel(),
                'destination_hash' => hash('sha256', $phone),
                'provider_reference' => ($reference !== null && $reference !== '') ? $reference : null,
                'status' => $status,
                'failure_reason' => $failure !== null ? mb_substr($failure, 0, 255) : null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
