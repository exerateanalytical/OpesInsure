<?php

declare(strict_types=1);

namespace App\Application\Documents\Engine;

use App\Application\DocumentCatalogue\DocumentCatalogueService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Single document catalogue (REQ-DUP-004): the registry agent's tables
 * document_types / document_packs / document_pack_items /
 * product_document_requirements (migration 2026_09_29_100001) are the source
 * of truth. The engine only READS them, through the registry read contract
 * App\Application\DocumentCatalogue\DocumentCatalogueService (types, typeFor,
 * packFor, requirementsFor). When those tables are not seeded yet
 * (fresh install before `opesinsure:seed-document-catalogue`), the same
 * catalogue's seed file database/data/document_catalogue_2026.json is read
 * instead — never a parallel definition.
 */
final class CatalogueSource
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $types = null;

    /** @var array<string, array{code: string, lifecycle_stage: string, items: array<int, array{canonical_code: string, requirement: string}>}>|null */
    private ?array $packs = null;

    public function fromDatabase(): bool
    {
        try {
            return Schema::hasTable('document_packs') && DB::table('document_packs')->exists() && DB::table('document_types')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, array<string, mixed>> keyed by canonical_code */
    public function types(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }
        $rows = [];
        if ($this->fromDatabase()) {
            // Registry read contract (DocumentCatalogueService), not a parallel query.
            foreach ($this->service()->types() as $t) {
                $rows[] = $t->toArray();
            }
        } else {
            $rows = $this->seed()['document_types'] ?? [];
        }
        $types = [];
        foreach ($rows as $r) {
            $code = (string) $r['canonical_code'];
            $types[$code] ??= [
                'code' => $code, 'id' => $r['type_id'], 'name_en' => $r['name_en'], 'name_fr' => $r['name_fr'] ?? $r['name_en'],
                'group_code' => $r['register_group_code'] ?? $r['category'] ?? 'CONTRACT', 'category' => $r['category'] ?? null,
                'origin' => $r['document_origin'] ?? null, 'is_evidence' => (bool) ($r['is_evidence'] ?? false),
                'security_level' => $r['security_level'] ?? null, 'numbering_family' => $r['numbering_family'] ?? null,
                'verifiable' => (bool) ($r['verifiable'] ?? false), 'family' => $r['parent_type_id'] ?? null,
                // Canonical spec security profile (CanonicalDocumentSpecSeeder); null before it is seeded.
                'canonical_spec_id' => $r['canonical_spec_id'] ?? null, 'security_tier' => $r['security_tier'] ?? null,
                'security_tier_ceiling' => $r['security_tier_ceiling'] ?? null, 'security_controls' => self::json($r['security_controls'] ?? null),
                'confidentiality_class' => $r['confidentiality_class'] ?? null, 'access_profiles' => self::json($r['access_profiles'] ?? null),
                'master_shell_code' => $r['master_shell_code'] ?? null,
            ];
        }

        return $this->types = $types;
    }

    /** @return array<int, array{canonical_code: string, requirement: string}> */
    public function packItems(string $packCode): array
    {
        return $this->packs()[$packCode]['items'] ?? [];
    }

    public function hasPack(string $packCode): bool
    {
        return isset($this->packs()[$packCode]);
    }

    /** @return array<string, array{code: string, lifecycle_stage: string, items: array<int, array{canonical_code: string, requirement: string}>}> */
    public function packs(): array
    {
        if ($this->packs !== null) {
            return $this->packs;
        }
        $packs = [];
        if ($this->fromDatabase()) {
            foreach ($this->service()->packs() as $pack) { // eager-loads active items + types
                $packs[$pack->code] = ['code' => $pack->code, 'lifecycle_stage' => $pack->lifecycle_stage, 'items' => $pack->items->sortBy('sort_order')
                    ->filter(fn ($i) => $i->documentType !== null)
                    ->map(fn ($i) => ['canonical_code' => $i->documentType->canonical_code, 'requirement' => $i->requirement])->values()->all()];
            }
        } else {
            foreach ($this->seed()['document_packs'] ?? [] as $p) {
                $packs[$p['code']] = ['code' => $p['code'], 'lifecycle_stage' => $p['lifecycle_stage'], 'items' => array_map(fn ($i) => ['canonical_code' => $i['canonical_code'], 'requirement' => $i['requirement']], $p['items'] ?? [])];
            }
        }

        return $this->packs = $packs;
    }

    /**
     * Resolved requirement matrix of a product version for a matrix stage
     * (its selected document product type + approved insurer overrides):
     * DocumentCatalogueService::requirementsFor. Empty when the product has no
     * document product type selected (packs alone then apply).
     *
     * @return array<int, array{canonical_code: string, level: string, source: string}>
     */
    public function requirementsFor(\App\Models\InsuranceProduct $product, string $stage): array
    {
        try {
            if (! $this->fromDatabase()) {
                return [];
            }

            return $this->service()->requirementsFor($product, $stage)
                ->filter(fn ($r) => ! empty($r['canonical_code']) && ! empty($r['level']) && empty($r['variant_code']))
                ->map(fn ($r) => ['canonical_code' => (string) $r['canonical_code'], 'level' => (string) $r['level'], 'source' => (string) ($r['source'] ?? '')])->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** Alias / id / code lookup through the registry (e.g. legacy codes). */
    public function canonicalCodeFor(string $idOrCode): ?string
    {
        try {
            return $this->fromDatabase() ? $this->service()->typeFor($idOrCode)?->canonical_code : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function json(mixed $v): ?array
    {
        return is_array($v) ? $v : (is_string($v) && $v !== '' ? json_decode($v, true) : null);
    }

    private function service(): DocumentCatalogueService
    {
        return app(DocumentCatalogueService::class);
    }

    /** @return array<string, mixed> */
    private function seed(): array
    {
        static $seed = null;
        $path = database_path('data/document_catalogue_2026.json');

        return $seed ??= (is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : []);
    }
}
