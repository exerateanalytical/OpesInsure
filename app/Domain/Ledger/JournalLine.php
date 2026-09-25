<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use DomainException;

final readonly class JournalLine
{
    public function __construct(public string $accountId, public int $debitMinor, public int $creditMinor)
    {
        if ($debitMinor < 0 || $creditMinor < 0 || ($debitMinor > 0 && $creditMinor > 0)) {
            throw new DomainException('A journal line must contain one non-negative side.');
        }
    }
}
