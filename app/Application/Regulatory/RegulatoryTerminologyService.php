<?php

declare(strict_types=1);

namespace App\Application\Regulatory;

use App\Models\Regulatory\MicroinsuranceBranch;
use App\Models\Regulatory\RegulatoryBranch;
use App\Models\Regulatory\RegulatoryReportingCategory;
use App\Models\Regulatory\RegulatoryTerm;
use Illuminate\Support\Facades\Cache;

/**
 * (regime, locale, semantic code) → controlled label. Translation is by
 * semantic code, never word-for-word; callers show `label`, never the code.
 * Only currently effective, ACTIVE rows are served. Cached; the seeder and
 * admin writes call flush().
 */
final class RegulatoryTerminologyService
{
    public const LOCALES = ['fr', 'en'];

    private const TTL = 3600;

    public function locale(?string $locale): string
    {
        $locale = strtolower(substr((string) $locale, 0, 2));

        return in_array($locale, self::LOCALES, true) ? $locale : 'fr';
    }

    /** @return list<array<string, mixed>> */
    public function terms(string $regime = 'CIMA', ?string $locale = 'fr', ?string $code = null, ?string $category = null): array
    {
        $locale = $this->locale($locale);
        $regime = strtoupper($regime);
        $all = Cache::remember($this->key("terms:{$regime}:{$locale}"), self::TTL, fn () => $this->loadTerms($regime, $locale));

        return array_values(array_filter($all, fn ($t) => ($code === null || $t['code'] === strtoupper($code)) && ($category === null || $t['category'] === strtoupper($category))));
    }

    public function label(string $code, ?string $locale = 'fr', string $regime = 'CIMA'): ?string
    {
        return $this->terms($regime, $locale, $code)[0]['label'] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function branches(?string $locale = 'fr', string $regime = 'CIMA'): array
    {
        $locale = $this->locale($locale);

        return Cache::remember($this->key("branches:{$regime}:{$locale}"), self::TTL, fn () => RegulatoryBranch::current()->where('regime', $regime)->orderBy('number')->get()
            ->map(fn (RegulatoryBranch $b) => [
                'number' => $b->number, 'code' => $b->code, 'label' => $locale === 'en' ? $b->label_en : $b->label_fr,
                'label_fr' => $b->label_fr, 'label_en' => $b->label_en, 'business_family' => $b->business_family, 'reserved' => $b->reserved,
                'accessory_allowed' => $b->accessory_allowed, 'complementary_covers_allowed' => $b->complementary_covers_allowed,
                'is_compulsory' => $b->is_compulsory, 'compulsory_basis' => $b->compulsory_basis, 'legal_reference' => $b->legal_reference,
                'regulatory_version' => $b->regulatory_version, 'effective_from' => $b->effective_from?->toDateString(), 'effective_until' => $b->effective_until?->toDateString(),
            ])->all());
    }

    /** @return list<array<string, mixed>> */
    public function microBranches(?string $locale = 'fr', string $regime = 'CIMA'): array
    {
        $locale = $this->locale($locale);

        return Cache::remember($this->key("micro:{$regime}:{$locale}"), self::TTL, fn () => $this->current(MicroinsuranceBranch::query())->where('regime', $regime)->orderBy('number')->get()
            ->map(fn (MicroinsuranceBranch $b) => [
                'number' => $b->number, 'code' => $b->code, 'label' => $locale === 'en' ? $b->label_en : $b->label_fr, 'label_fr' => $b->label_fr, 'label_en' => $b->label_en,
                'business_family' => $b->business_family, 'legal_reference' => $b->legal_reference, 'regulatory_version' => $b->regulatory_version,
            ])->all());
    }

    /** @return array{categories: list<array<string, mixed>>, intermediary_measures: list<array<string, mixed>>} */
    public function reportingCategories(?string $locale = 'fr', string $regime = 'CIMA'): array
    {
        $locale = $this->locale($locale);

        return Cache::remember($this->key("reporting:{$regime}:{$locale}"), self::TTL, function () use ($locale, $regime) {
            $rows = $this->current(RegulatoryReportingCategory::query())->where('regime', $regime)->orderBy('kind')->orderBy('sequence')->get();
            $shape = fn (RegulatoryReportingCategory $r) => [
                'code' => $r->code, 'sequence' => $r->sequence, 'label' => $locale === 'en' ? $r->label_en : $r->label_fr,
                'legal_reference' => $r->legal_reference, 'regulatory_version' => $r->regulatory_version,
            ];

            return [
                'categories' => $rows->where('kind', 'ART_411_CATEGORY')->map($shape)->values()->all(),
                'intermediary_measures' => $rows->where('kind', 'ART_557_MEASURE')->map($shape)->values()->all(),
            ];
        });
    }

    public function flush(): void
    {
        Cache::forever('regulatory:generation', (int) Cache::get('regulatory:generation', 0) + 1);
    }

    private function key(string $suffix): string
    {
        return 'regulatory:'.Cache::get('regulatory:generation', 0).':'.$suffix;
    }

    private function current($query)
    {
        $on = now()->toDateString();

        return $query->where('status', 'ACTIVE')->whereDate('effective_from', '<=', $on)->where(fn ($w) => $w->whereNull('effective_until')->orWhereDate('effective_until', '>=', $on));
    }

    /** @return list<array<string, mixed>> */
    private function loadTerms(string $regime, string $locale): array
    {
        $terms = $this->current(RegulatoryTerm::query())->where('regime', $regime)->with('translations')->orderBy('namespace')->orderBy('code')->get();
        // Party roles carry no labels of their own; they reuse the CIMA_TERM label of the same code when one exists.
        $byCode = $terms->where('namespace', 'CIMA_TERM')->keyBy('code');

        return $terms->map(function (RegulatoryTerm $t) use ($locale, $byCode) {
            $source = $t->translations->isNotEmpty() ? $t : ($byCode[$t->code] ?? $t);
            $alternatives = $source->translations->where('locale', $locale)->where('context', 'ALTERNATIVE')->pluck('label')->values()->all();

            return [
                'code' => $t->code, 'namespace' => $t->namespace, 'category' => $t->category, 'label' => $source->label($locale),
                'alternatives' => $alternatives, 'source_article' => $t->source_article, 'notes' => $t->notes, 'regulatory_version' => $t->regulatory_version,
            ];
        })->values()->all();
    }
}
