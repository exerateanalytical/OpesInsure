<?php

declare(strict_types=1);

namespace App\Application\Notifications\Adapters;

final class TwilioSmsAdapter extends AbstractTwilioAdapter
{
    public function channel(): string
    {
        return 'SMS';
    }

    protected function fromAddress(): string
    {
        return (string) config('services.twilio.sms_from');
    }

    protected function formatRecipient(string $destination): string
    {
        return $destination;
    }
}
