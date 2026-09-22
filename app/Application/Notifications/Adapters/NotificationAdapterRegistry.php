<?php

declare(strict_types=1);

namespace App\Application\Notifications\Adapters;

use InvalidArgumentException;

final class NotificationAdapterRegistry
{
    public function for(string $channel): NotificationChannelAdapter
    {
        return match ($channel) {
            'SMS' => new TwilioSmsAdapter,
            'WHATSAPP' => new TwilioWhatsAppAdapter,
            'EMAIL' => new SmtpEmailAdapter,
            default => throw new InvalidArgumentException('Unsupported notification channel.'),
        };
    }
}
