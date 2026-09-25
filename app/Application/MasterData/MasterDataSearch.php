<?php

declare(strict_types=1);

namespace App\Application\MasterData;

use Illuminate\Support\Facades\DB;

/**
 * Search over a list: canonical code, EN + FR labels, aliases and
 * abbreviations, accent-insensitive ("medecin" → Médecin) and typo-tolerant
 * (Levenshtein on words). Ranking: tenant-frequent → Cameroon-common → exact
 * → prefix → alias → contains → fuzzy. Rare values are never hidden: an empty
 * query returns the whole (narrowed) list. "Other / Not listed" is always last.
 */
final class MasterDataSearch
{
    public function __construct(private readonly MasterDataCatalogue $catalogue) {}

    /** @return array<int, array<string, mixed>>|null null when the list does not exist */
    public function search(string $domain, string $list, ?string $q, ?string $parent = null, ?string $tenantId = null, int $limit = 50): ?array
    {
        $l = $this->catalogue->list($domain, $list);
        if (! $l) {
            return null;
        }
        [$domain] = $this->catalogue->canonical($domain, $list);
        $l = $this->catalogue->forTenant($l, $tenantId, $domain);
        $values = collect($l['values']);
        if ($parent !== null && $parent !== '') {
            $values = $values->filter(fn ($v) => ($v['is_other'] ?? false) || ! isset($v['parent']) || $v['parent'] === $parent
                || in_array($parent, (array) ($v['attributes']['also_parents'] ?? $v['attributes']['categories'] ?? []), true));
        }
        $tenantUse = $tenantId ? DB::table('master_data_value_usage')->where('tenant_id', $tenantId)->whereIn('value_id', $values->pluck('id'))->pluck('uses', 'value_id')->all() : [];
        $needle = MasterDataNormalizer::normalize($q);

        $scored = $values->map(function ($v) use ($needle, $tenantUse) {
            $score = $needle === '' ? 0 : $this->score($needle, $v);
            if ($needle !== '' && $score === 0) {
                return null;
            }
            $boost = min(($tenantUse[$v['id']] ?? 0), 50) * 2 + (($v['common'] ?? false) ? 40 : 0) + min($v['usage'] ?? 0, 20);

            return $v + ['_score' => $score + $boost, '_other' => (bool) ($v['is_other'] ?? false)];
        })->filter();

        $other = $values->first(fn ($v) => $v['is_other'] ?? false);
        $ranked = $scored->reject(fn ($v) => $v['_other'])
            ->sort(fn ($a, $b) => [$b['_score'], $a['label']['en']] <=> [$a['_score'], $b['label']['en']])
            ->take($limit)->values();
        if ($other) {
            $ranked->push($other + ['_score' => 0]);
        }

        return $ranked->map(function ($v) {
            unset($v['search'], $v['_other']);
            $v['score'] = $v['_score'];
            unset($v['_score']);

            return $v;
        })->all();
    }

    private function score(string $needle, array $v): int
    {
        $code = MasterDataNormalizer::normalize(str_replace('_', ' ', $v['code']));
        $en = MasterDataNormalizer::normalize($v['label']['en']);
        $fr = MasterDataNormalizer::normalize($v['label']['fr']);
        $aliases = array_map([MasterDataNormalizer::class, 'normalize'], $v['aliases'] ?? []);
        if (in_array($needle, [$code, $en, $fr], true)) {
            return 1000;
        }
        if (str_starts_with($en, $needle) || str_starts_with($fr, $needle)) {
            return 800;
        }
        if (in_array($needle, $aliases, true)) {
            return 700;
        }
        foreach ($aliases as $a) {
            if (str_starts_with($a, $needle)) {
                return 600;
            }
        }
        if (str_contains($v['search'] ?? '', ' '.$needle) ) {
            return 500;
        }
        if (str_contains($v['search'] ?? '', $needle)) {
            return 400;
        }
        // Typo tolerance: each query word must match some label word closely.
        $words = array_unique(preg_split('/[\s|]+/', trim($v['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY));
        $total = 0;
        foreach (explode(' ', $needle) as $q) {
            if (strlen($q) < 3) {
                continue;
            }
            $best = PHP_INT_MAX;
            foreach ($words as $w) {
                $cmp = strlen($w) > strlen($q) + 2 ? substr($w, 0, strlen($q)) : $w;
                $best = min($best, levenshtein($q, $cmp));
            }
            $allowed = strlen($q) <= 4 ? 1 : 2;
            if ($best > $allowed) {
                return 0;
            }
            $total += 300 - $best * 50;
        }

        return $total;
    }
}
