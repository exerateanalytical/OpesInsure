<?php

declare(strict_types=1);

namespace App\Application\Settings;

use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * REQ-TMP-003 — the IANA timezone picker list. Source of truth is master data
 * (domain geography, list country, attributes.timezones — IANA per the list's
 * source_reference). Only identifiers PHP also knows are offered, so a value
 * picked here can always be used by Carbon. If master data is not seeded the
 * picker falls back to PHP's IANA list (never an invented list).
 */
final class TimezoneCatalogue
{
    /** @var array<string, array{timezone: string, countries: list<string>}>|null */
    private ?array $cache = null;

    /** @return list<array{timezone: string, countries: list<string>}> */
    public function all(): array
    {
        return array_values($this->load());
    }

    /** @return array<string, string> tz => "tz (CM)" for Filament selects */
    public function options(): array
    {
        $out = [];
        foreach ($this->load() as $tz => $row) {
            $out[$tz] = $row['countries'] === [] ? $tz : $tz.' ('.implode(', ', array_slice($row['countries'], 0, 3)).')';
        }

        return $out;
    }

    public function isValid(?string $timezone): bool
    {
        return is_string($timezone) && isset($this->load()[$timezone]);
    }

    public function source(): string
    {
        $this->load();

        return $this->fromMasterData ? 'MASTER_DATA_GEOGRAPHY' : 'IANA_FALLBACK';
    }

    private bool $fromMasterData = false;

    /** @return array<string, array{timezone: string, countries: list<string>}> */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $known = array_flip(DateTimeZone::listIdentifiers());
        $map = [];
        try {
            $rows = DB::table('master_data_values')
                ->where('domain_code', 'geography')->where('list_code', 'country')->where('status', 'ACTIVE')->whereNull('tenant_id')
                ->get(['code', 'attributes']);
            foreach ($rows as $row) {
                $attrs = json_decode((string) $row->attributes, true) ?: [];
                foreach ((array) ($attrs['timezones'] ?? []) as $tz) {
                    if (is_string($tz) && isset($known[$tz])) {
                        $map[$tz]['timezone'] = $tz;
                        $map[$tz]['countries'][] = (string) $row->code;
                    }
                }
            }
        } catch (Throwable) {
            $map = [];
        }
        $this->fromMasterData = $map !== [];
        if ($map === []) {
            foreach (array_keys($known) as $tz) {
                $map[$tz] = ['timezone' => $tz, 'countries' => []];
            }
        }
        ksort($map);

        return $this->cache = $map;
    }
}
