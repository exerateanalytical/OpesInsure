<?php

declare(strict_types=1);

namespace App\Application\Cases\Sla;

use App\Application\Temporal\BusinessCalendar;
use App\Application\Temporal\BusinessTime;
use App\Application\Temporal\TimezoneResolver;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * REQ-CAL-001 — business-minute arithmetic for SLAs (ICE E6 INV-6.3).
 *
 * Extends, does not replace, the Batch 1 Temporal BusinessCalendar:
 *  - weekends + business_calendars holidays come from BusinessCalendar::isBusinessDay();
 *  - calendar_exceptions add HOLIDAY/CLOSURE (closed) and EXTRA_DAY (open) dates,
 *    jurisdiction-wide or per branch;
 *  - calendar_business_hours give opening windows per ISO weekday; branch rows
 *    override the jurisdiction rows for that branch.
 *
 * Timezone: when none is passed it comes from TimezoneResolver::forBranch()
 * (branch > organisation > platform default), never a hard-coded default.
 *
 * No hours are invented: when a jurisdiction has no hours configured, every
 * business day counts as a full 24h day (hoursSource() = UNCONFIGURED) so SLAs
 * still run and the gap is visible. When hours ARE configured they define the
 * working weekdays; weekend dates then only get exceptions (holidays for a
 * configured Saturday must be entered as calendar_exceptions).
 *
 * Owner decision #22: calendar_breaks optionally exclude a lunch/break window per calendar
 * (jurisdiction, optionally branch; branch rows replace the jurisdiction rows). No break is ever
 * inferred. Public holidays also come from ACTIVE reference_datasets (kind PUBLIC_HOLIDAYS),
 * the versioned institutional dataset; calendar_exceptions still win (EXTRA_DAY re-opens a date).
 */
final class BusinessHoursCalendar
{
    private const MAX_DAYS = 3660;

    /** @var array<string, mixed> */
    private array $cache = [];

    public function __construct(private readonly BusinessCalendar $calendar, private readonly TimezoneResolver $timezones) {}

    /** Branch timezone, else organisation, else platform default (REQ-TMP-003 via TimezoneResolver). */
    public function timezone(?string $branchId = null, ?string $tenantId = null): string
    {
        return $this->timezones->forBranch($branchId, $tenantId);
    }

    public function hoursSource(string $jurisdiction = 'CM', ?string $branchId = null): string
    {
        if ($branchId !== null && DB::table('calendar_business_hours')->where('jurisdiction', $jurisdiction)->where('branch_id', $branchId)->exists()) {
            return 'BRANCH';
        }

        return DB::table('calendar_business_hours')->where('jurisdiction', $jurisdiction)->whereNull('branch_id')->exists() ? 'JURISDICTION' : 'UNCONFIGURED';
    }

    /**
     * Opening windows on one local date, as [open, close] instants.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function windows(DateTimeInterface|string $date, string $jurisdiction = 'CM', ?string $branchId = null, ?string $timezone = null): array
    {
        $timezone ??= $this->timezone($branchId);
        $day = BusinessTime::parse($date, $timezone)->startOfDay();
        $ds = $day->toDateString();
        $key = "{$jurisdiction}|{$branchId}|{$timezone}|{$ds}";
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $exception = $this->exception($ds, $jurisdiction, $branchId);
        $hours = $this->hoursRows($jurisdiction, $branchId, $ds);

        if ($exception === 'HOLIDAY' || $exception === 'CLOSURE') {
            return $this->cache[$key] = [];
        }
        if ($hours === null) { // UNCONFIGURED: whole business day
            $open = $exception === 'EXTRA_DAY' || $this->calendar->isBusinessDay($ds, $jurisdiction);

            return $this->cache[$key] = $this->withoutBreaks($open ? [[$day, $day->addDay()]] : [], $ds, $day->isoWeekday(), $jurisdiction, $branchId, $timezone);
        }

        $weekday = $day->isoWeekday();
        $rows = array_values(array_filter($hours, fn ($h) => (int) $h->weekday === $weekday));
        if ($rows === [] && $exception === 'EXTRA_DAY') {
            // An extra working day on a normally closed weekday uses the first configured weekday's hours.
            $first = min(array_map(fn ($h) => (int) $h->weekday, $hours));
            $rows = array_values(array_filter($hours, fn ($h) => (int) $h->weekday === $first));
        }
        if ($rows === []) {
            return $this->cache[$key] = [];
        }
        // Weekday holidays from business_calendars (BusinessCalendar owns weekend+holiday rules).
        if ($exception !== 'EXTRA_DAY' && $weekday <= 5 && ! $this->calendar->isBusinessDay($ds, $jurisdiction)) {
            return $this->cache[$key] = [];
        }

        $out = [];
        foreach ($rows as $h) {
            $out[] = [$this->at($ds, $h->opens, $timezone), $this->at($ds, $h->closes, $timezone)];
        }
        usort($out, fn ($a, $b) => $a[0] <=> $b[0]);

        return $this->cache[$key] = $this->withoutBreaks($out, $ds, $weekday, $jurisdiction, $branchId, $timezone);
    }

    /**
     * The instant $days business days after $start: the same local time on the Nth following day that has
     * opening windows, clamped into that day's windows. Returned as an instant so callers can store it as
     * business minutes (pauses then work in minutes).
     */
    public function addBusinessDays(DateTimeInterface|string $start, int $days, string $jurisdiction = 'CM', ?string $branchId = null, ?string $timezone = null): CarbonImmutable
    {
        $timezone ??= $this->timezone($branchId);
        $cursor = $this->nextOpen(BusinessTime::parse($start, $timezone), $jurisdiction, $branchId, $timezone);
        if ($days <= 0) {
            return $cursor;
        }
        $timeOfDay = $cursor->format('H:i:s');
        $day = $cursor->startOfDay();
        for ($i = 0, $n = 0; $i < self::MAX_DAYS; $i++) {
            $day = $day->addDay();
            $windows = $this->windows($day, $jurisdiction, $branchId, $timezone);
            if ($windows === [] || ++$n < $days) {
                continue;
            }
            $target = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $day->toDateString().' '.$timeOfDay, $timezone);
            if ($target < $windows[0][0]) {
                return $windows[0][0];
            }
            $best = $windows[0][1];
            foreach ($windows as [$open, $close]) {
                if ($target >= $open && $target <= $close) {
                    return $target;
                }
                if ($open <= $target) {
                    $best = $close;
                }
            }

            return $best;
        }

        throw new RuntimeException('No business days found within '.self::MAX_DAYS." days for {$jurisdiction}.");
    }

    /** Business minutes covered by $days business days from $start (what an SLA clock stores). */
    public function businessDaysAsMinutes(DateTimeInterface|string $start, int $days, string $jurisdiction = 'CM', ?string $branchId = null, ?string $timezone = null): int
    {
        $timezone ??= $this->timezone($branchId);

        return max(1, $this->businessMinutesBetween($start, $this->addBusinessDays($start, $days, $jurisdiction, $branchId, $timezone), $jurisdiction, $branchId, $timezone));
    }

    /** First instant >= $at that lies inside an opening window. */
    public function nextOpen(DateTimeInterface|string $at, string $jurisdiction = 'CM', ?string $branchId = null, ?string $timezone = null): CarbonImmutable
    {
        $timezone ??= $this->timezone($branchId);
        $cursor = BusinessTime::parse($at, $timezone);
        $day = $cursor->startOfDay();
        for ($i = 0; $i < self::MAX_DAYS; $i++, $day = $day->addDay()) {
            foreach ($this->windows($day, $jurisdiction, $branchId, $timezone) as [$open, $close]) {
                if ($close > $cursor) {
                    return $open > $cursor ? $open : $cursor;
                }
            }
        }

        throw new RuntimeException('No business hours found within '.self::MAX_DAYS." days for {$jurisdiction}.");
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $windows
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function withoutBreaks(array $windows, string $date, int $weekday, string $jurisdiction, ?string $branchId, string $timezone): array
    {
        if ($windows === []) {
            return [];
        }
        $q = fn ($branch) => DB::table('calendar_breaks')->where('jurisdiction', $jurisdiction)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch), fn ($q) => $q->whereNull('branch_id'))
            ->where(fn ($q) => $q->whereNull('weekday')->orWhere('weekday', $weekday))
            ->whereDate('valid_from', '<=', $date)->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->get(['starts', 'ends'])->all();
        $breaks = $branchId ? $q($branchId) : [];
        if ($breaks === [] && ! ($branchId && DB::table('calendar_breaks')->where('jurisdiction', $jurisdiction)->where('branch_id', $branchId)->exists())) {
            $breaks = $q(null);
        }
        foreach ($breaks as $b) {
            [$bs, $be] = [$this->at($date, $b->starts, $timezone), $this->at($date, $b->ends, $timezone)];
            $next = [];
            foreach ($windows as [$open, $close]) {
                if ($be <= $open || $bs >= $close) {
                    $next[] = [$open, $close];

                    continue;
                }
                if ($bs > $open) {
                    $next[] = [$open, $bs];
                }
                if ($be < $close) {
                    $next[] = [$be, $close];
                }
            }
            $windows = $next;
        }

        return $windows;
    }

    /** The instant that lies $minutes business minutes after $start. */
    public function addBusinessMinutes(DateTimeInterface|string $start, int $minutes, string $jurisdiction = 'CM', ?string $branchId = null, ?string $timezone = null): CarbonImmutable
    {
        $timezone ??= $this->timezone($branchId);
        $cursor = BusinessTime::parse($start, $timezone);
        if ($minutes <= 0) {
            return $cursor;
        }
        $remaining = $minutes * 60;
        $day = $cursor->startOfDay();
        for ($i = 0; $i < self::MAX_DAYS; $i++, $day = $day->addDay()) {
            foreach ($this->windows($day, $jurisdiction, $branchId, $timezone) as [$open, $close]) {
                if ($close <= $cursor) {
                    continue;
                }
                $from = $open > $cursor ? $open : $cursor;
                $available = $close->getTimestamp() - $from->getTimestamp();
                if ($remaining <= $available) {
                    return $from->addSeconds($remaining);
                }
                $remaining -= $available;
            }
        }

        throw new RuntimeException("No business hours found within ".self::MAX_DAYS." days for {$jurisdiction}.");
    }

    /** Business minutes elapsed in [$from, $to) (0 when $to <= $from). */
    public function businessMinutesBetween(DateTimeInterface|string $from, DateTimeInterface|string $to, string $jurisdiction = 'CM', ?string $branchId = null, ?string $timezone = null): int
    {
        $timezone ??= $this->timezone($branchId);
        $a = BusinessTime::parse($from, $timezone);
        $b = BusinessTime::parse($to, $timezone);
        if ($b <= $a) {
            return 0;
        }
        $seconds = 0;
        for ($day = $a->startOfDay(); $day < $b; $day = $day->addDay()) {
            foreach ($this->windows($day, $jurisdiction, $branchId, $timezone) as [$open, $close]) {
                $s = max($open->getTimestamp(), $a->getTimestamp());
                $e = min($close->getTimestamp(), $b->getTimestamp());
                if ($e > $s) {
                    $seconds += $e - $s;
                }
            }
        }

        return intdiv($seconds, 60);
    }

    public function isOpenAt(DateTimeInterface|string $instant, string $jurisdiction = 'CM', ?string $branchId = null, ?string $timezone = null): bool
    {
        $timezone ??= $this->timezone($branchId);
        $t = BusinessTime::parse($instant, $timezone);
        foreach ($this->windows($t, $jurisdiction, $branchId, $timezone) as [$open, $close]) {
            if ($t >= $open && $t < $close) {
                return true;
            }
        }

        return false;
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    private function at(string $date, string $time, string $tz): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date.' '.substr($time.':00', 0, 8), $tz);
    }

    private function exception(string $date, string $jurisdiction, ?string $branchId): ?string
    {
        $rows = DB::table('calendar_exceptions')->where('jurisdiction', $jurisdiction)->whereDate('date', $date)
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($q) => $q->orWhere('branch_id', $branchId)))
            ->get(['kind', 'branch_id']);
        if ($rows->isEmpty()) {
            // Versioned institutional holiday dataset (owner decision: never hard-coded).
            $holiday = DB::table('public_holiday_entries as h')->join('reference_datasets as d', 'd.id', '=', 'h.dataset_id')
                ->where('d.kind', 'PUBLIC_HOLIDAYS')->where('d.status', 'ACTIVE')->where('d.jurisdiction', $jurisdiction)
                ->whereDate('h.date', $date)->whereDate('d.effective_from', '<=', $date)
                ->where(fn ($q) => $q->whereNull('d.effective_until')->orWhereDate('d.effective_until', '>=', $date))->exists();

            return $holiday ? 'HOLIDAY' : null;
        }
        // Branch-specific exception wins over the jurisdiction-wide one.
        $branch = $rows->firstWhere('branch_id', '!=', null);

        return ($branch ?? $rows->first())->kind;
    }

    /** @return list<object>|null null = no hours configured for this jurisdiction/branch */
    private function hoursRows(string $jurisdiction, ?string $branchId, string $date): ?array
    {
        $q = fn ($branch) => DB::table('calendar_business_hours')->where('jurisdiction', $jurisdiction)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch), fn ($q) => $q->whereNull('branch_id'))
            ->whereDate('valid_from', '<=', $date)->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->get(['weekday', 'opens', 'closes'])->all();

        $rows = $branchId ? $q($branchId) : [];
        if ($rows === []) {
            $rows = $q(null);
        }

        return $rows === [] ? null : $rows;
    }
}
