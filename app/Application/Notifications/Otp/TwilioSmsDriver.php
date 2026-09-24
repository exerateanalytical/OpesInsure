<?php

declare(strict_types=1);

namespace App\Application\Notifications\Otp;

final class TwilioSmsDriver extends TwilioOtpDriver
{
    public function channel(): string
    {
        return 'sms';
    }

    protected function from(): string
    {
        return $this->settings->twilio()['sms_from'];
    }

    protected function address(string $number): string
    {
        return $number;
    }
}
