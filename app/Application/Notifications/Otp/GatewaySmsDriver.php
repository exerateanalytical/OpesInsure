<?php

declare(strict_types=1);

namespace App\Application\Notifications\Otp;

use App\Application\Notifications\Sms\SmsGateway;

/**
 * OTP over the admin-configured SMS providers (Integrations → SMS providers). Tried before the legacy
 * ETECH/Twilio platform-settings drivers; failover between PRIMARY and FALLBACK happens inside SmsGateway.
 */
final class GatewaySmsDriver implements OtpChannel
{
    public function __construct(private SmsGateway $gateway)
    {
    }

    public function provider(): string
    {
        return 'gateway';
    }

    public function channel(): string
    {
        return 'sms';
    }

    public function isConfigured(): bool
    {
        return $this->gateway->isConfigured();
    }

    public function send(string $phoneE164, string $code, string $message): string
    {
        $result = $this->gateway->send($phoneE164, $message, 'OTP');

        return mb_substr($result['provider'].':'.$result['reference'], 0, 190);
    }
}
