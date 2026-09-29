<?php

declare(strict_types=1);

namespace App\Application\Notifications\Adapters;

use App\Application\Notifications\Sms\SmsGateway;

/** Notification SMS through the admin-configured providers (primary → fallback), see SmsGateway. */
final class GatewaySmsAdapter implements NotificationChannelAdapter
{
    public function __construct(private SmsGateway $gateway)
    {
    }

    public function channel(): string
    {
        return 'SMS';
    }

    public function send(string $destination, string $subject, string $body, string $idempotencyKey): ChannelSendResult
    {
        $r = $this->gateway->send($destination, $body, 'NOTIFICATION');

        return new ChannelSendResult($r['provider'], $r['reference'] !== '' ? $r['reference'] : $idempotencyKey,
            ['encoding' => $r['encoding'], 'segments' => $r['segments']]);
    }
}
