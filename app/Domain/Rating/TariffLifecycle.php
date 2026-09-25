<?php

declare(strict_types=1);

namespace App\Domain\Rating;

use DomainException;

/**
 * REQ-RAT-002 — PRE §75 tariff workflow DRAFT → REVIEW → APPROVAL → SCHEDULED → ACTIVE → EXPIRED.
 * Stored names: IN_REVIEW = REVIEW, APPROVED = approved-not-yet-scheduled. History rows are
 * append-only in tariff_status_history; rules never change after DRAFT (rules_hash check).
 */
final class TariffLifecycle
{
    /** Statuses a rating may resolve (valid time still decides which one applies on a date). */
    public const RATEABLE = ['APPROVED', 'SCHEDULED', 'ACTIVE', 'EXPIRED'];

    /** Statuses that occupy an effective window (overlap guard). */
    public const OCCUPYING = ['APPROVED', 'SCHEDULED', 'ACTIVE', 'EXPIRED'];

    public const TRANSITIONS = [
        'submit' => [['DRAFT'], 'IN_REVIEW'],
        'reject' => [['IN_REVIEW'], 'REJECTED'],
        'approve' => [['IN_REVIEW'], 'APPROVED'],
        'schedule' => [['APPROVED'], 'SCHEDULED'],
        'activate' => [['APPROVED', 'SCHEDULED'], 'ACTIVE'],
        'expire' => [['APPROVED', 'SCHEDULED', 'ACTIVE'], 'EXPIRED'],
    ];

    public static function target(string $event, string $from): string
    {
        [$allowed, $to] = self::TRANSITIONS[$event] ?? throw new DomainException("Unknown tariff event {$event}.");
        if (! in_array($from, $allowed, true)) {
            throw new DomainException("Tariff cannot {$event} from {$from}.");
        }

        return $to;
    }
}
