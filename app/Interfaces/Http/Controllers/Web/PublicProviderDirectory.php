<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Web;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only view of the official Cameroon insurance register (DGTCFM/MINFI,
 * seeded from database/data/cameroon_insurance_register_2026.json) for the
 * public website. Only rows flagged is_official_register are exposed — demo
 * carriers never appear publicly. Every figure is a live count; when the
 * register cannot be read the callers get null and render a non-numeric
 * statement instead of an invented number.
 */
final class PublicProviderDirectory
{
    private const TTL = 600;

    /** @return array{insurers: ?int, brokers: ?int, countries: ?int, country_codes: list<string>} */
    public function stats(): array
    {
        return $this->remember('stats', function (): array {
            $countries = DB::table('carriers')->where('is_official_register', true)->whereNotNull('country_code')->distinct()->pluck('country_code')
                ->merge(DB::table('partners')->where('is_official_register', true)->whereNotNull('country_code')->distinct()->pluck('country_code'))
                ->unique()->values()->all();

            return [
                'insurers' => DB::table('carriers')->where('is_official_register', true)->count(),
                'brokers' => DB::table('partners')->where('is_official_register', true)->where('type', 'BROKER')->count(),
                'countries' => count($countries),
                'country_codes' => $countries,
            ];
        }, ['insurers' => null, 'brokers' => null, 'countries' => null, 'country_codes' => []]);
    }

    /** @return list<array{name: string, short: string, kind: string, branch: ?string, city: ?string, phone: ?string, email: ?string, website: ?string, website_host: ?string, branch_count: int, verification_status: ?string}> */
    public function all(): array
    {
        return $this->remember('all', function (): array {
            $cities = fn (string $table) => DB::table($table)
                ->join('party_addresses', 'party_addresses.party_id', '=', $table.'.party_id')
                ->where($table.'.is_official_register', true)
                ->orderByDesc('party_addresses.is_primary')
                ->pluck('party_addresses.city', $table.'.id');

            $insurerCities = $cities('carriers');
            // Institutional directory: head-office city, contacts, branch count and verification.
            $hq = DB::table('carriers')->join('party_addresses', 'party_addresses.party_id', '=', 'carriers.party_id')
                ->where('carriers.is_official_register', true)->where('party_addresses.type', 'HEAD_OFFICE')->pluck('party_addresses.city', 'carriers.id');
            $profiles = DB::table('institution_profiles')->get(['carrier_id', 'website', 'phones', 'emails', 'verification_status'])->keyBy('carrier_id');
            $labels = \App\Models\Directory\InstitutionVerificationLabel::map();
            $branchCounts = DB::table('institution_offices')->selectRaw('carrier_id, count(*) as n')->groupBy('carrier_id')->pluck('n', 'carrier_id');
            $brokerCities = $cities('partners');
            $logos = \App\Models\Letterhead\LetterheadAsset::where('owner_type', 'CARRIER')->where('status', 'ACTIVE')->where('public_display', true)
                ->whereNotNull('logo_path')->orderBy('version')->get()->keyBy('carrier_id');

            $insurers = DB::table('carriers')->where('is_official_register', true)
                ->orderBy('regulator_sequence')->get(['id', 'trade_name', 'legal_name', 'short_name', 'licence_branch'])
                ->map(fn ($r) => [
                    'name' => (string) ($r->trade_name ?: $r->legal_name),
                    'short' => (string) ($r->short_name ?: ($r->trade_name ?: $r->legal_name)),
                    'kind' => 'insurer',
                    'branch' => $r->licence_branch === 'LIFE' ? 'LIFE' : ($r->licence_branch ? 'IARD' : null),
                    'city' => $hq[$r->id] ?? $insurerCities[$r->id] ?? null,
                    'logo_url' => \App\Application\Documents\Letterhead\LetterheadResolver::publicLogoUrl($logos->get($r->id)),
                ] + self::contactOf($profiles->get($r->id), (int) ($branchCounts[$r->id] ?? 0), $labels));

            $brokers = DB::table('partners')->where('is_official_register', true)->where('type', 'BROKER')
                ->orderBy('regulator_sequence')->orderBy('legal_name')->get(['id', 'trade_name', 'legal_name'])
                ->map(fn ($r) => [
                    'name' => (string) ($r->trade_name ?: $r->legal_name),
                    'short' => (string) ($r->trade_name ?: $r->legal_name),
                    'kind' => 'broker',
                    'branch' => null,
                    'city' => $brokerCities[$r->id] ?? null,
                    'logo_url' => null,
                ] + self::contactOf(null, 0));

            return $insurers->concat($brokers)->values()->all();
        }, []);
    }

    /**
     * Home-page strip: one entry per insurer brand (IARD licences first, the
     * matching life company is the same brand), in register order.
     *
     * @return list<array{name: string, short: string, kind: string, branch: ?string, city: ?string}>
     */
    public function featured(int $limit = 8): array
    {
        $seen = [];

        return collect($this->all())
            ->where('kind', 'insurer')
            ->sortBy(fn ($p) => $p['branch'] === 'IARD' ? 0 : 1)
            ->filter(function ($p) use (&$seen) {
                $brand = strtoupper(strtok(preg_replace('/[^A-Za-z0-9 ]/', ' ', $p['short']) ?: $p['short'], ' ') ?: $p['short']);
                if (isset($seen[$brand])) {
                    return false;
                }

                return $seen[$brand] = true;
            })
            ->take($limit)->values()->all();
    }

    /**
     * @param  array{q?: ?string, type?: ?string, branch?: ?string, city?: ?string}  $filters
     * @return list<array{name: string, short: string, kind: string, branch: ?string, city: ?string}>
     */
    public function search(array $filters): array
    {
        $q = mb_strtolower(trim((string) ($filters['q'] ?? '')));

        return collect($this->all())
            ->when(in_array($filters['type'] ?? null, ['insurer', 'broker'], true), fn ($c) => $c->where('kind', $filters['type']))
            ->when(in_array($filters['branch'] ?? null, ['IARD', 'LIFE'], true), fn ($c) => $c->where('branch', $filters['branch']))
            ->when(($filters['city'] ?? '') !== '', fn ($c) => $c->filter(fn ($p) => $p['city'] !== null && mb_strtolower($p['city']) === mb_strtolower((string) $filters['city'])))
            ->when($q !== '', fn ($c) => $c->filter(fn ($p) => str_contains(mb_strtolower($p['name'].' '.$p['short']), $q)))
            ->values()->all();
    }

    /** @return list<string> */
    public function cities(): array
    {
        return collect($this->all())->pluck('city')->filter()->unique()->sort()->values()->all();
    }

    /** @return array{phone: ?string, email: ?string, website: ?string, website_host: ?string, branch_count: int, verification_status: ?string, verification_label: ?array{en: string, fr: string}} */
    private static function contactOf(?object $profile, int $branches, array $labels = []): array
    {
        $first = fn (?string $json) => $json ? (json_decode($json, true)[0] ?? null) : null;
        $website = $profile?->website;

        return [
            'phone' => $first($profile?->phones),
            'email' => $first($profile?->emails),
            'website' => $website,
            'website_host' => $website ? preg_replace('/^www\./', '', (string) parse_url($website, PHP_URL_HOST)) : null,
            'branch_count' => $branches,
            'verification_status' => $profile?->verification_status,
            'verification_label' => $profile?->verification_status ? ($labels[$profile->verification_status] ?? null) : null,
        ];
    }

    /** Short initials for the typographic badge (no third-party logos). */
    public static function initials(string $short): string
    {
        $words = preg_split('/[\s&\-\/]+/u', trim(preg_replace('/\b(SARL|SA|S\.A\.|Assurances?|Insurance|Cameroun|Cameroon|Courtage|Conseil)\b/iu', '', $short) ?: $short)) ?: [];
        $words = array_values(array_filter($words, fn ($w) => $w !== ''));
        if ($words === []) {
            return mb_strtoupper(mb_substr($short, 0, 2));
        }
        if (count($words) === 1) {
            return mb_strtoupper(mb_substr($words[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
    }

    /** Deterministic brand-palette tint for a badge. */
    public static function tone(string $name): int
    {
        return crc32($name) % 5;
    }

    private function remember(string $key, callable $load, mixed $fallback): mixed
    {
        try {
            return Cache::remember('public_site.directory.'.$key, self::TTL, $load);
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }
    }
}
