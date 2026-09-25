<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use DomainException;

/** Batch 10-7 REQ-ACC-002: journal lifecycle. Automatic postings are born POSTED; MANUAL journals walk the full chain. */
enum JournalStatus: string
{
    case Draft = 'DRAFT';
    case Validated = 'VALIDATED';
    case Approved = 'APPROVED';
    case Posted = 'POSTED';
    case Reversed = 'REVERSED';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::Validated],
            self::Validated => [self::Approved, self::Draft],
            self::Approved => [self::Posted, self::Draft],
            self::Posted => [self::Reversed],
            self::Reversed => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    public function assertCanMoveTo(self $to): void
    {
        if (! $this->canMoveTo($to)) {
            throw new DomainException("Journal cannot move from {$this->value} to {$to->value}.");
        }
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
