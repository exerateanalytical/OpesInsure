<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Web;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read model for the public marketplace, category directories and the compare
 * page: every ACTIVE (published) product version with its insurer, the covers
 * attached to it and the base premium of its latest APPROVED tariff. Nothing is
 * invented — a product without an approved tariff shows "quote on request".
 */
final class PublicMarketplace
{
    /** URL slug => catalogue line_code (same seven lines as the landing page). */
    public const LINES = [
        'motor' => 'MOTOR', 'health' => 'HEALTH', 'travel' => 'TRAVEL', 'home' => 'HOME',
        'business' => 'BUSINESS', 'life' => 'LIFE', 'accident' => 'ACCIDENT',
    ];

    public const PER_PAGE = 8;

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        try {
            return Cache::remember('public-site:marketplace:v1:'.app()->getLocale(), 600, fn () => $this->load());
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * @param  array{line?: string, q?: string, providers?: list<string>, price?: string, sort?: string}  $f
     * @return list<array<string, mixed>>
     */
    public function filter(array $f): array
    {
        $q = mb_strtolower(trim($f['q'] ?? ''));
        $rows = array_filter($this->all(), function (array $p) use ($f, $q): bool {
            if (! empty($f['line']) && $p['line'] !== $f['line']) {
                return false;
            }
            if (! empty($f['providers']) && ! in_array($p['carrier_key'], $f['providers'], true)) {
                return false;
            }
            if ($q !== '' && ! str_contains($p['search'], $q)) {
                return false;
            }

            return self::inPriceBand($p['premium'], $f['price'] ?? '');
        });

        $sort = $f['sort'] ?? 'popular';
        usort($rows, match ($sort) {
            'price_asc' => fn ($a, $b) => [$a['premium'] === null, $a['premium']] <=> [$b['premium'] === null, $b['premium']],
            'price_desc' => fn ($a, $b) => [$a['premium'] === null, -($a['premium'] ?? 0)] <=> [$b['premium'] === null, -($b['premium'] ?? 0)],
            'name' => fn ($a, $b) => strcasecmp($a['name'], $b['name']),
            // "Most popular": most covers first, then cheapest.
            default => fn ($a, $b) => [-count($a['covers']), $a['premium'] ?? PHP_INT_MAX] <=> [-count($b['covers']), $b['premium'] ?? PHP_INT_MAX],
        });

        return array_values($rows);
    }

    /** @return array<string, int> line slug => number of products */
    public function lineCounts(): array
    {
        $counts = array_fill_keys(array_keys(self::LINES), 0);
        foreach ($this->all() as $p) {
            $counts[$p['slug']]++;
        }

        return $counts;
    }

    /**
     * Provider facet for a line (or all lines): carrier key => [name, count].
     *
     * @return array<string, array{name: string, n: int}>
     */
    public function providers(?string $line = null): array
    {
        $out = [];
        foreach ($this->all() as $p) {
            if ($line && $p['line'] !== $line) {
                continue;
            }
            $out[$p['carrier_key']] ??= ['name' => $p['carrier'], 'n' => 0];
            $out[$p['carrier_key']]['n']++;
        }
        uasort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /** @return list<array<string, mixed>> products in the given order, unknown codes dropped */
    public function byCodes(array $codes): array
    {
        $byCode = array_column($this->all(), null, 'code');

        return array_values(array_filter(array_map(fn ($c) => $byCode[$c] ?? null, $codes)));
    }

    /** Premium bands (FCFA per year) used by the filter rail. */
    public const PRICE_BANDS = ['lt50' => [0, 50_000], '50to100' => [50_000, 100_000], '100to150' => [100_000, 150_000], 'gt150' => [150_000, null]];

    public static function inPriceBand(?int $premium, string $band): bool
    {
        if ($band === '' || ! isset(self::PRICE_BANDS[$band])) {
            return true;
        }
        if ($premium === null) {
            return false;
        }
        [$lo, $hi] = self::PRICE_BANDS[$band];

        return $premium >= $lo && ($hi === null || $premium < $hi);
    }

    public static function money(?int $xaf): string
    {
        return $xaf === null ? '' : number_format($xaf, 0, '.', ',').' FCFA';
    }

    /** @return list<array<string, mixed>> */
    private function load(): array
    {
        $slugOf = array_flip(self::LINES);
        $locale = app()->getLocale();

        $products = DB::table('insurance_products as p')
            ->join('carriers as c', 'c.id', '=', 'p.carrier_id')
            ->where('p.status', 'ACTIVE')
            ->whereIn('p.line_code', array_values(self::LINES))
            ->where(fn ($q) => $q->whereNull('p.effective_until')->orWhere('p.effective_until', '>=', now()->toDateString()))
            ->orderBy('p.name')
            ->get(['p.id', 'p.code', 'p.name', 'p.line_code', 'c.id as carrier_id', 'c.short_name', 'c.trade_name', 'c.legal_name']);

        if ($products->isEmpty()) {
            return [];
        }
        $ids = $products->pluck('id')->all();

        $tariffs = DB::table('tariff_versions')->whereIn('insurance_product_id', $ids)->where('status', 'APPROVED')
            ->orderBy('version')->get(['insurance_product_id', 'rules'])->keyBy('insurance_product_id');

        $covers = DB::table('product_coverages as pc')
            ->join('coverage_definitions as cd', 'cd.id', '=', 'pc.coverage_definition_id')
            ->whereIn('pc.insurance_product_id', $ids)
            ->orderBy('pc.display_order')
            ->get(['pc.insurance_product_id', 'cd.code', 'cd.name', 'pc.is_optional', 'pc.default_limit_minor', 'pc.default_deductible_minor'])
            ->groupBy('insurance_product_id');

        // Only admin-uploaded, public-display letterhead logos (never scraped) — same rule as the provider directory.
        $logos = \App\Models\Letterhead\LetterheadAsset::where('owner_type', 'CARRIER')->where('status', 'ACTIVE')->where('public_display', true)
            ->whereNotNull('logo_path')->orderBy('version')->get()->keyBy('carrier_id');

        return $products->map(function ($p) use ($slugOf, $tariffs, $covers, $locale, $logos): array {
            $rules = json_decode((string) ($tariffs[$p->id]->rules ?? 'null'), true);
            $base = is_array($rules) && isset($rules['base_premium_minor']) ? intdiv((int) $rules['base_premium_minor'], 100) : null;
            $list = collect($covers[$p->id] ?? [])->map(function ($c) use ($locale): array {
                $name = json_decode((string) $c->name, true);
                $label = is_array($name) ? ($name[$locale] ?? $name['en'] ?? reset($name)) : (string) $c->name;

                return ['code' => $c->code, 'name' => (string) $label, 'optional' => (bool) $c->is_optional,
                    'limit' => $c->default_limit_minor !== null ? intdiv((int) $c->default_limit_minor, 100) : null,
                    'deductible' => $c->default_deductible_minor ? intdiv((int) $c->default_deductible_minor, 100) : null];
            })->values()->all();
            $carrier = (string) ($p->short_name ?: ($p->trade_name ?: $p->legal_name));

            return [
                'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'line' => $p->line_code, 'slug' => $slugOf[$p->line_code],
                'carrier' => $carrier, 'carrier_full' => (string) ($p->trade_name ?: $p->legal_name), 'carrier_key' => (string) $p->carrier_id,
                'logo_url' => \App\Application\Documents\Letterhead\LetterheadResolver::publicLogoUrl($logos->get($p->carrier_id)),
                'premium' => $base, 'covers' => $list,
                'cover_max' => collect($list)->max('limit'),
                'deductible' => collect($list)->pluck('deductible')->filter()->min(),
                'search' => mb_strtolower($p->name.' '.$carrier.' '.$p->line_code.' '.collect($list)->pluck('name')->implode(' ')),
            ];
        })->all();
    }
}
