<?php

declare(strict_types=1);

namespace App\Application\Notifications\Sms;

use DomainException;

/**
 * An SMS could not be sent. $status: FAILED (a provider refused / was unreachable — the next provider is tried),
 * RATE_LIMITED (per-number limit hit — nothing else is tried), CONFIG_REQUIRED (no active provider),
 * REJECTED (the message itself is invalid, e.g. too long). Messages never contain credentials.
 */
final class SmsDeliveryException extends DomainException
{
    public function __construct(string $message, public readonly string $status = 'FAILED')
    {
        parent::__construct($message);
    }
}
