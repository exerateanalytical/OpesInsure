<?php

declare(strict_types=1);

namespace App\Application\Temporal;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * REQ-TMP-001 timezone / business-date rules (ICE §0.3):
 *  - The business timezone is the tenant/branch timezone, default Africa/Douala.
 *  - A date-only value ("2026-03-01") means the START of that business day in
 *    the business timezone, not UTC midnight.
 *  - The business date of an instant is its calendar date in the business
 *    timezone (so 23:30Z on 31 Dec is 1 Jan in Douala, UTC+1).
 *  - Day-granularity validity is closed-closed [from, until] on business dates.
 */
final class BusinessTime
{
    public const DEFAULT_TIMEZONE = 'Africa/Douala';

    public static function parse(DateTimeInterface|string $value, string $timezone = self::DEFAULT_TIMEZONE): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone($timezone);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        }

        return CarbonImmutable::parse($value, $timezone)->setTimezone($timezone);
    }

    public static function businessDate(DateTimeInterface|string $value, string $timezone = self::DEFAULT_TIMEZONE): string
    {
        return self::parse($value, $timezone)->toDateString();
    }

    /** Closed-closed day-granularity validity check. */
    public static function withinDays(DateTimeInterface|string $at, DateTimeInterface|string $from, DateTimeInterface|string|null $until, string $timezone = self::DEFAULT_TIMEZONE): bool
    {
        $d = self::businessDate($at, $timezone);

        return self::businessDate($from, $timezone) <= $d && ($until === null || self::businessDate($until, $timezone) >= $d);
    }
}
