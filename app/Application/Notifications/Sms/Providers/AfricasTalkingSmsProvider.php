<?php

declare(strict_types=1);

namespace App\Application\Notifications\Sms\Providers;

use App\Application\Notifications\Sms\SmsDeliveryException;
use App\Application\Notifications\Sms\SmsProvider;
use App\Models\SmsProviderConnection;
use Illuminate\Support\Facades\Http;

/**
 * Africa's Talking bulk SMS: POST https://api.africastalking.com/version1/messaging (sandbox: api.sandbox.africastalking.com),
 * header apiKey, form username/to/message[/from]. Success = recipient status "Success" (statusCode 100/101/102).
 */
final class AfricasTalkingSmsProvider implements SmsProvider
{
    public const LIVE_URL = 'https://api.africastalking.com/version1/messaging';

    public const SANDBOX_URL = 'https://api.sandbox.africastalking.com/version1/messaging';

    public function key(): string
    {
        return 'africastalking';
    }

    public function requiredSettings(): array
    {
        return ['username'];
    }

    public function requiredSecrets(): array
    {
        return ['api_key'];
    }

    public function send(SmsProviderConnection $connection, string $toE164, string $message): string
    {
        $form = ['username' => (string) $connection->setting('username'), 'to' => $toE164, 'message' => $message];
        if (($from = (string) $connection->setting('sender_id', '')) !== '') {
            $form['from'] = $from;
        }

        $response = Http::asForm()->acceptJson()->withHeaders(['apiKey' => $connection->secret('api_key')])->timeout(15)
            ->post($connection->setting('sandbox') ? self::SANDBOX_URL : self::LIVE_URL, $form);

        if (! $response->successful()) {
            throw new SmsDeliveryException("Africa's Talking refused the SMS (HTTP ".$response->status().').');
        }

        $recipient = (array) $response->json('SMSMessageData.Recipients.0', []);
        if (($recipient['status'] ?? null) !== 'Success') {
            $why = (string) ($recipient['status'] ?? $response->json('SMSMessageData.Message', 'no recipient accepted'));
            throw new SmsDeliveryException("Africa's Talking did not accept the SMS: ".mb_substr($why, 0, 120));
        }

        return mb_substr((string) ($recipient['messageId'] ?? ''), 0, 190);
    }
}
