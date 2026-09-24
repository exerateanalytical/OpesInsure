<?php

declare(strict_types=1);

namespace App\Application\Notifications\Otp;

final class TwilioWhatsAppDriver extends TwilioOtpDriver
{
    public function channel(): string
    {
        return 'whatsapp';
    }

    protected function from(): string
    {
        return $this->settings->twilio()['whatsapp_from'];
    }

    protected function address(string $number): string
    {
        return str_starts_with($number, 'whatsapp:') ? $number : 'whatsapp:'.$number;
    }
}
