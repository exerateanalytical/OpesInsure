<?php

declare(strict_types=1);

namespace App\Application\Notifications\Adapters;

use DomainException;
use Illuminate\Support\Facades\Http;

/**
 * SMS and WhatsApp both go through Twilio's same Messages resource
 * (https://www.twilio.com/docs/sms/api/message-resource) — they differ only
 * in the From/To address format ("whatsapp:+E164" vs plain "+E164") and the
 * configured sender number, so that's the only thing subclasses supply.
 */
abstract class AbstractTwilioAdapter implements NotificationChannelAdapter
{
    abstract protected function fromAddress(): string;

    abstract protected function formatRecipient(string $destination): string;

    public function send(string $destination, string $subject, string $body, string $idempotencyKey): ChannelSendResult
    {
        $accountSid = (string) config('services.twilio.account_sid');
        $authToken = (string) config('services.twilio.auth_token');
        $from = $this->fromAddress();

        if ($accountSid === '' || $authToken === '' || $from === '') {
            throw new DomainException('Twilio is not configured.');
        }

        $response = Http::asForm()
            ->withBasicAuth($accountSid, $authToken)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->timeout(15)
            ->retry(2, 250, throw: false)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", [
                'From' => $this->formatRecipient($from),
                'To' => $this->formatRecipient($destination),
                'Body' => $body,
                'StatusCallback' => route('notifications.twilio.callback'),
            ]);

        if (! $response->successful()) {
            throw new DomainException('Twilio rejected the message: '.(string) $response->json('message', $response->status()));
        }

        $sid = (string) $response->json('sid');

        if ($sid === '') {
            throw new DomainException('Twilio response did not include a message sid.');
        }

        return new ChannelSendResult('twilio', $sid, [
            'status' => $response->json('status'),
            'http_status' => $response->status(),
        ]);
    }
}
