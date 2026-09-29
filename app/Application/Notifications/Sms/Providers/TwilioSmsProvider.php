<?php

declare(strict_types=1);

namespace App\Application\Notifications\Sms\Providers;

use App\Application\Notifications\Sms\SmsDeliveryException;
use App\Application\Notifications\Sms\SmsProvider;
use App\Models\SmsProviderConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Twilio Messages API: POST /2010-04-01/Accounts/{sid}/Messages.json (Basic sid:token; From, To, Body). */
final class TwilioSmsProvider implements SmsProvider
{
    public function key(): string
    {
        return 'twilio';
    }

    public function requiredSettings(): array
    {
        return ['from'];
    }

    public function requiredSecrets(): array
    {
        return ['account_sid', 'auth_token'];
    }

    public function send(SmsProviderConnection $connection, string $toE164, string $message): string
    {
        $sid = $connection->secret('account_sid');

        $response = Http::asForm()->withBasicAuth($sid, $connection->secret('auth_token'))
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->timeout(15)
            ->post('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($sid).'/Messages.json', [
                'From' => (string) $connection->setting('from'),
                'To' => $toE164,
                'Body' => $message,
            ]);

        if (! $response->successful()) {
            throw new SmsDeliveryException('Twilio refused the SMS (HTTP '.$response->status().'): '.mb_substr((string) $response->json('message', ''), 0, 120));
        }

        return (string) $response->json('sid', '');
    }
}
