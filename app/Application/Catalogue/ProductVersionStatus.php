<?php

declare(strict_types=1);

namespace App\Application\Catalogue;

use InvalidArgumentException;

/**
 * REQ-PRD-001 — PRE §8 version lifecycle DRAFT→REVIEW→APPROVED→PUBLISHED→SUSPENDED→RETIRED.
 *
 * Storage keeps the values the rest of the platform already reads
 * (IN_REVIEW, ACTIVE — temporal registry, quotes, one-active index);
 * this class is the single mapping between spec and storage vocabulary.
 */
final class ProductVersionStatus
{
    public const DRAFT = 'DRAFT';
    public const REVIEW = 'REVIEW';
    public const APPROVED = 'APPROVED';
    public const PUBLISHED = 'PUBLISHED';
    public const SUSPENDED = 'SUSPENDED';
    public const RETIRED = 'RETIRED';
    public const REJECTED = 'REJECTED';

    public const ALL = [self::DRAFT, self::REVIEW, self::APPROVED, self::PUBLISHED, self::SUSPENDED, self::RETIRED, self::REJECTED];

    private const TO_STORAGE = [self::REVIEW => 'IN_REVIEW', self::PUBLISHED => 'ACTIVE'];

    /** Allowed spec transitions (REJECTED returns work to a new draft version). */
    public const TRANSITIONS = [
        self::DRAFT => [self::REVIEW, self::RETIRED],
        self::REVIEW => [self::APPROVED, self::PUBLISHED, self::REJECTED],
        self::APPROVED => [self::PUBLISHED, self::REJECTED],
        self::PUBLISHED => [self::SUSPENDED, self::RETIRED],
        self::SUSPENDED => [self::PUBLISHED, self::RETIRED],
        self::RETIRED => [],
        self::REJECTED => [],
    ];

    public static function toStorage(string $status): string
    {
        $status = strtoupper($status);
        if (! in_array($status, self::ALL, true)) {
            throw new InvalidArgumentException("Unknown product version status {$status}");
        }

        return self::TO_STORAGE[$status] ?? $status;
    }

    public static function fromStorage(string $stored): string
    {
        return array_flip(self::TO_STORAGE)[$stored] ?? $stored;
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
