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

final class Journal
{
    /** @param list<JournalLine> $lines */
    public function __construct(public readonly string $currency, public readonly array $lines)
    {
        if (count($lines) < 2) {
            throw new DomainException('A journal requires at least two lines.');
        }
        $debits = array_sum(array_map(fn (JournalLine $line) => $line->debitMinor, $lines));
        $credits = array_sum(array_map(fn (JournalLine $line) => $line->creditMinor, $lines));
        if ($debits !== $credits || $debits === 0) {
            throw new DomainException('Journal is not balanced.');
        }
    }
}
