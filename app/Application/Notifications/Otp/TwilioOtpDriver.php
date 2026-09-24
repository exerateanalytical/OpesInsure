<?php

declare(strict_types=1);

namespace App\Application\Notifications\Otp;

use App\Application\Settings\PlatformSettings;
use DomainException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Twilio Messages API for SMS or WhatsApp, reading admin settings first (.env fallback). */
abstract class TwilioOtpDriver implements OtpChannel
{
    public function __construct(protected PlatformSettings $settings)
    {
    }

    abstract protected function from(): string;

    abstract protected function address(string $number): string;

    public function provider(): string
    {
        return 'twilio';
    }

    public function isConfigured(): bool
    {
        $c = $this->settings->twilio();

        return $c['enabled'] && $c['account_sid'] !== '' && $c['auth_token'] !== '' && $this->from() !== '';
    }

    public function send(string $phoneE164, string $code, string $message): string
    {
        $c = $this->settings->twilio();

        $response = Http::asForm()
            ->withBasicAuth($c['account_sid'], $c['auth_token'])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->timeout(15)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$c['account_sid']}/Messages.json", [
                'From' => $this->address($this->from()),
                'To' => $this->address($phoneE164),
                'Body' => $message,
            ]);

        if (! $response->successful()) {
            throw new DomainException('Twilio rejected the message: '.(string) $response->json('message', $response->status()));
        }

        return (string) $response->json('sid');
    }
}
