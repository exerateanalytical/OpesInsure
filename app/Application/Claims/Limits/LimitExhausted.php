<?php

declare(strict_types=1);

namespace App\Application\Claims\Limits;

/** REQ-CLM-004: a limit movement was refused (reason codes: limit_exhausted, insufficient_reserve, …). */
final class LimitExhausted extends \DomainException
{
    public function __construct(public readonly string $reasonCode, string $message, public readonly ?string $limitId = null, public readonly int $remainingMinor = 0)
    {
        parent::__construct($message);
    }
}
