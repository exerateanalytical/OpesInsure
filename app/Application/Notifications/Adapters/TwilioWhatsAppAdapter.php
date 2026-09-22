<?php

declare(strict_types=1);

namespace App\Application\Notifications\Adapters;

final class TwilioWhatsAppAdapter extends AbstractTwilioAdapter
{
    public function channel(): string
    {
        return 'WHATSAPP';
    }

    protected function fromAddress(): string
    {
        return (string) config('services.twilio.whatsapp_from');
    }

    protected function formatRecipient(string $destination): string
    {
        return str_starts_with($destination, 'whatsapp:') ? $destination : 'whatsapp:'.$destination;
    }
}
