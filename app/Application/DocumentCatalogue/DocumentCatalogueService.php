<?php

declare(strict_types=1);

namespace App\Application\DocumentCatalogue;

use App\Models\DocumentCatalogue\DocumentPack;
use App\Models\DocumentCatalogue\DocumentType;
use Illuminate\Support\Collection;

/**
 * Read contract of the canonical document registry for the document engine,
 * APIs and admin screens. Types are addressed by stable type_id (DOC-001…,
 * CLM-nn, FIN-nn…, EVD-nnn) or by code; legacy/draft codes resolve through
 * aliases. Resolution order for a code: REGISTER → EVIDENCE → subtype
 * namespaces → aliases.
 */
final class DocumentCatalogueService
{
    public function find(string $idOrCode): ?DocumentType
    {
        $key = strtoupper(trim($idOrCode));
        $hit = DocumentType::where('type_id', $key)->first();
        if ($hit) {
            return $hit;
        }
        $byCode = DocumentType::where('canonical_code', $key)->get()
            ->sortBy(fn (DocumentType $t) => match ($t->namespace) { 'REGISTER' => 0, 'EVIDENCE' => 1, default => 2 })->first();

        return $byCode ?? DocumentType::whereJsonContains('aliases', $key)->first();
    }

    /** Engine read contract: type by id / code / alias (null when unknown). */
    public function typeFor(string $idOrCode): ?DocumentType
    {
        return $this->find($idOrCode);
    }

    /** Engine read contract: active pack by code with active items (+ types), or null. */
    public function packFor(string $packCode): ?DocumentPack
    {
        return DocumentPack::query()->active()->where('code', strtoupper($packCode))
            ->with(['items' => fn ($q) => $q->active()->with('documentType')])->first();
    }

    /**
     * Engine read contract: resolved requirement matrix for a product type, or for
     * a product version (its selected product type + approved insurer overrides).
     * Empty when a product has no document product type selected.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function requirementsFor(string|\App\Models\InsuranceProduct $productTypeOrProduct, ?string $stage = null): Collection
    {
        $resolver = app(DocumentRequirementResolver::class);
        if ($productTypeOrProduct instanceof \App\Models\InsuranceProduct) {
            $code = $resolver->productTypeFor($productTypeOrProduct);
            $rows = $code ? $resolver->resolve($code, $productTypeOrProduct) : collect();
        } else {
            $rows = $resolver->resolve($productTypeOrProduct);
        }

        return $stage ? $rows->where('stage', strtoupper($stage))->values() : $rows;
    }

    /** @param array{group?:?string,stage?:?string,class?:?string,kind?:?string,origin?:?string,evidence?:?bool} $f */
    public function types(array $f = []): Collection
    {
        return DocumentType::query()->active()
            ->when($f['group'] ?? null, fn ($q, $g) => $q->where(fn ($w) => $w->where('category', strtoupper($g))->orWhere('register_group_code', strtoupper($g))->orWhere('register_group', strtoupper($g))))
            ->when($f['stage'] ?? null, fn ($q, $s) => $q->whereJsonContains('stages', strtoupper($s)))
            ->when($f['kind'] ?? null, fn ($q, $k) => $q->where('kind', strtoupper($k)))
            ->when($f['origin'] ?? null, fn ($q, $o) => $q->where('document_origin', strtoupper($o)))
            ->when(isset($f['evidence']), fn ($q) => $q->where('is_evidence', (bool) $f['evidence']))
            ->when($f['class'] ?? null, fn ($q, $c) => $q->whereIn('type_id', fn ($s) => $s->select('document_type_id')->from('document_type_class_applicability')
                ->where('class_code', strtoupper($c))->where('status', 'ACTIVE')))
            ->orderBy('type_id')->get();
    }

    /** Class packs plus universal packs; @param array{class?:?string,stage?:?string} $f */
    public function packs(array $f = []): Collection
    {
        return DocumentPack::query()->active()->with(['items' => fn ($q) => $q->active()->with('documentType')])
            ->when($f['class'] ?? null, fn ($q, $c) => $q->where(fn ($w) => $w->whereJsonContains('class_codes', strtoupper($c))->orWhere('is_universal', true)))
            ->when($f['stage'] ?? null, fn ($q, $s) => $q->where('lifecycle_stage', strtoupper($s)))
            ->orderBy('is_universal')->orderBy('code')->get();
    }
}
