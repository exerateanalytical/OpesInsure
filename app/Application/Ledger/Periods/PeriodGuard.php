<?php

declare(strict_types=1);

namespace App\Application\Ledger\Periods;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-ACC-003 / ESR FIN-023: the single period check for journal writes.
 * Tenants without any accounting_periods rows are not period-controlled (pass-through).
 */
final class PeriodGuard
{
    /** Statuses that accept postings. CLOSING still accepts (pre-close checklist phase). */
    public const POSTABLE = ['OPEN', 'CLOSING', 'REOPENED'];

    /** Refuse a posting dated in a CLOSED period. */
    public static function assertOpen(?string $tenantId, DateTimeInterface|string $date): void
    {
        $period = self::periodFor($tenantId, $date);
        if ($period && $period->status === 'CLOSED') {
            throw ValidationException::withMessages(['accounting_date' => "Accounting period {$period->fiscal_year}-{$period->period_number} is closed."]);
        }
    }

    /**
     * Effective accounting date for an automatic posting: the date itself when its period is postable,
     * otherwise the first day of the first later postable period. Refuses when none exists.
     */
    public static function postingDate(?string $tenantId, DateTimeInterface|string $date): string
    {
        $day = CarbonImmutable::parse($date)->toDateString();
        $period = self::periodFor($tenantId, $day);
        if ($period && $period->status === 'CLOSED') {
            $next = DB::table('accounting_periods')->where('tenant_id', $tenantId)->where('starts_on', '>', $day)
                ->whereIn('status', self::POSTABLE)->orderBy('starts_on')->first();
            $day = $next ? CarbonImmutable::parse($next->starts_on)->toDateString() : $day;
        }
        self::assertOpen($tenantId, $day);

        return $day;
    }

    public static function periodFor(?string $tenantId, DateTimeInterface|string $date): ?object
    {
        if ($tenantId === null) {
            return null;
        }
        $day = CarbonImmutable::parse($date)->toDateString();

        return DB::table('accounting_periods')->where('tenant_id', $tenantId)->where('starts_on', '<=', $day)->where('ends_on', '>=', $day)->first();
    }
}
