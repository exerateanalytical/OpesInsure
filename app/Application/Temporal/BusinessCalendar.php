<?php

declare(strict_types=1);

namespace App\Application\Temporal;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * REQ-TMP-001 — ICE E1 §1.4 BusinessCalendar over the existing
 * business_calendars table (jurisdiction, year, holidays jsonb, timezone).
 * Weekend = Saturday/Sunday. Holidays are only what the table holds; no
 * public-holiday list is invented here. businessHoursBetween is deferred to
 * Engine 6 because working hours are not yet an agreed fact (UNVERIFIED).
 */
final class BusinessCalendar
{
    /** @var array<string, array{holidays: array<string, true>, timezone: string}> */
    private array $cache = [];

    public function isBusinessDay(DateTimeInterface|string $date, string $jurisdiction = 'CM'): bool
    {
        $d = BusinessTime::parse($date, $this->timezone($jurisdiction, $date));
        if ($d->isWeekend()) {
            return false;
        }

        return ! isset($this->year($jurisdiction, $d->year)['holidays'][$d->toDateString()]);
    }

    public function addBusinessDays(DateTimeInterface|string $date, int $n, string $jurisdiction = 'CM'): CarbonImmutable
    {
        $d = BusinessTime::parse($date, $this->timezone($jurisdiction, $date))->startOfDay();
        $step = $n >= 0 ? 1 : -1;
        $remaining = abs($n);
        while ($remaining > 0) {
            $d = $d->addDays($step);
            if ($this->isBusinessDay($d, $jurisdiction)) {
                $remaining--;
            }
        }

        return $d;
    }

    private function timezone(string $jurisdiction, DateTimeInterface|string $date): string
    {
        $year = (int) substr(BusinessTime::businessDate($date), 0, 4);

        return $this->year($jurisdiction, $year)['timezone'];
    }

    /** @return array{holidays: array<string, true>, timezone: string} */
    private function year(string $jurisdiction, int $year): array
    {
        return $this->cache["{$jurisdiction}:{$year}"] ??= (function () use ($jurisdiction, $year) {
            $row = DB::table('business_calendars')->where('jurisdiction', $jurisdiction)->where('year', $year)->first();
            $holidays = [];
            foreach (json_decode($row->holidays ?? '[]', true) ?: [] as $h) {
                $date = is_array($h) ? ($h['date'] ?? null) : $h;
                if (is_string($date)) {
                    $holidays[substr($date, 0, 10)] = true;
                }
            }

            return ['holidays' => $holidays, 'timezone' => $row->timezone ?? BusinessTime::DEFAULT_TIMEZONE];
        })();
    }
}
