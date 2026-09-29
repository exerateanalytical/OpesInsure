<?php

declare(strict_types=1);

namespace App\Application\Notifications\Adapters;

use InvalidArgumentException;

final class NotificationAdapterRegistry
{
    public function for(string $channel): NotificationChannelAdapter
    {
        return match ($channel) {
            // S14: admin-configured SMS providers when any is active, else the .env Twilio account.
            'SMS' => app(\App\Application\Notifications\Sms\SmsGateway::class)->isConfigured()
                ? new GatewaySmsAdapter(app(\App\Application\Notifications\Sms\SmsGateway::class)) : new TwilioSmsAdapter,
            'WHATSAPP' => new TwilioWhatsAppAdapter,
            'EMAIL' => new SmtpEmailAdapter,
            default => throw new InvalidArgumentException('Unsupported notification channel.'),
        };
    }
}
