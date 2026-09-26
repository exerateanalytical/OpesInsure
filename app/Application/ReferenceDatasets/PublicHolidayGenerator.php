<?php

declare(strict_types=1);

namespace App\Application\ReferenceDatasets;

use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gap Closure Pack v1 file 02 (REQ-CAL-001): yearly public-holiday dataset from the seeded rule list
 * calendars.public_holiday_rule (Law No. 73/5 of 7 December 1973, as amended).
 *
 *  - FIXED_DATE rules → that date; CHRISTIAN_MOVABLE → Western Easter + attributes.easter_offset_days.
 *  - Civil holiday falling on a Sunday → the following day is added (adjacent_day_rule); other bridge days need a decree.
 *  - ANNUAL_OFFICIAL_CONFIRMATION (Eid al-Fitr, Eid al-Adha) is never computed: returned as `pending` so the maker
 *    adds the officially announced date (CONFIG_REQUIRED gate).
 *
 * The result is only ever a DRAFT, UNVERIFIED reference_datasets version; ReferenceDatasetService maker-checker
 * activation is what makes it count in BusinessCalendar / BusinessHoursCalendar. Nothing is seeded.
 */
final class PublicHolidayGenerator
{
    public function __construct(private readonly ReferenceDatasetService $datasets) {}

    /** @return array{entries: list<array<string, string>>, pending: list<array<string, string>>, source_reference: ?string, source_url: ?string} */
    public function preview(int $year): array
    {
        $rules = DB::table('master_data_values')->where(['domain_code' => 'calendars', 'list_code' => 'public_holiday_rule', 'status' => 'ACTIVE'])
            ->whereNull('tenant_id')->orderBy('sort_order')->get();
        if ($rules->isEmpty()) {
            throw new ApiProblemException('HOLIDAY_RULES_MISSING', 409, 'No public-holiday rules are loaded (calendars.public_holiday_rule).');
        }
        $easter = CarbonImmutable::create($year, 3, 21)->addDays(self::easterDays($year));
        $entries = $pending = [];
        $sourceRef = $sourceUrl = null;
        foreach ($rules as $r) {
            $a = json_decode((string) $r->attributes, true) ?: [];
            $sourceRef ??= $a['legal_basis'] ?? null;
            $sourceUrl ??= $a['source_url'] ?? null;
            $ref = $a['legal_basis'] ?? null;
            $date = match ($a['calculation'] ?? 'FIXED_DATE') {
                'FIXED_DATE' => isset($a['month'], $a['day']) ? CarbonImmutable::create($year, (int) $a['month'], (int) $a['day']) : null,
                'CHRISTIAN_MOVABLE' => isset($a['easter_offset_days']) ? $easter->addDays((int) $a['easter_offset_days']) : null,
                default => null,
            };
            if ($date === null) {
                $pending[] = ['code' => $r->code, 'label' => $r->label_en, 'reason' => 'Date set each year by official announcement (CONFIG_REQUIRED).'];
                continue;
            }
            $type = ($a['calculation'] ?? '') === 'CHRISTIAN_MOVABLE' ? 'RELIGIOUS_MOVABLE' : 'PUBLIC';
            $entries[] = ['date' => $date->toDateString(), 'label' => "{$r->label_en} / {$r->label_fr}", 'holiday_type' => $type, 'legal_reference' => $ref];
            if (($a['sunday_substitute'] ?? false) && $date->isSunday()) {
                $entries[] = ['date' => $date->addDay()->toDateString(), 'label' => "{$r->label_en} (following day) / {$r->label_fr} (lendemain)", 'holiday_type' => 'PUBLIC', 'legal_reference' => $ref];
            }
        }
        usort($entries, fn ($x, $y) => [$x['date'], $x['label']] <=> [$y['date'], $y['label']]);

        return ['entries' => $entries, 'pending' => $pending, 'source_reference' => $sourceRef, 'source_url' => $sourceUrl];
    }

    /**
     * Draft the year's dataset. $extra lets the maker add officially announced dates (Eid, decreed bridge days).
     *
     * @param  list<array<string, string>>  $extra
     * @return array{dataset: object, pending: list<array<string, string>>}
     */
    public function draft(int $year, User $maker, string $jurisdiction = 'CM', array $extra = []): array
    {
        $p = $this->preview($year);
        $entries = [...$p['entries'], ...$extra];
        $covered = array_map(fn ($e) => strtoupper((string) ($e['code'] ?? '')), $extra);
        $pending = array_values(array_filter($p['pending'], fn ($x) => ! in_array($x['code'], $covered, true)));
        $dataset = $this->datasets->draft([
            'kind' => 'PUBLIC_HOLIDAYS', 'jurisdiction' => $jurisdiction, 'code' => "{$jurisdiction}_PUBLIC_HOLIDAYS_{$year}",
            'source_name' => 'Gap Closure Pack v1 / public_holiday_rule (generated)', 'source_reference' => $p['source_reference'], 'source_url' => $p['source_url'],
            'effective_from' => "{$year}-01-01", 'effective_until' => "{$year}-12-31", 'verification_status' => 'UNVERIFIED',
            'verification_note' => $pending ? 'Missing officially announced dates: '.implode(', ', array_column($pending, 'code')) : null,
            'entries' => array_map(fn ($e) => array_intersect_key($e, array_flip(['date', 'label', 'holiday_type', 'legal_reference'])), $entries),
        ], $maker);

        return ['dataset' => $dataset, 'pending' => $pending];
    }

    /** Days after 21 March to Western Easter Sunday (anonymous Gregorian algorithm; ext-calendar not required). */
    public static function easterDays(int $y): int
    {
        $a = $y % 19;
        $b = intdiv($y, 100);
        $c = $y % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return (int) CarbonImmutable::create($y, 3, 21)->diffInDays(CarbonImmutable::create($y, $month, $day));
    }
}
