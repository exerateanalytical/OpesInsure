<?php

declare(strict_types=1);

namespace App\Application\Notifications\Otp;

/**
 * One way of getting a one-time code to a phone: a provider (etech|twilio)
 * over a channel (sms|whatsapp). Throws on any failure so OtpDeliveryService
 * can fall back to the next driver.
 */
interface OtpChannel
{
    public function provider(): string;

    public function channel(): string;

    public function isConfigured(): bool;

    /** @return string provider message reference (empty when the provider returns none) */
    public function send(string $phoneE164, string $code, string $message): string;
}
