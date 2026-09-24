<?php

declare(strict_types=1);

namespace App\Application\DocumentCatalogue;

use App\Models\DocumentCatalogue\DocumentProductType;
use App\Models\DocumentCatalogue\DocumentRequirementMatrixEntry as Entry;
use App\Models\DocumentCatalogue\ProductDocumentRequirement;
use App\Models\InsuranceProduct;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Resolves the Document Requirement Matrix for a product type:
 *   universal baseline (section 3) → ancestors (extends, root first) → own entries,
 * later layers overriding earlier ones on (stage, document_type_id, variant_code).
 * For a product version, ACTIVE MATRIX_OVERRIDE rows then adjust levels
 * (insurer-specific) without touching platform data.
 */
final class DocumentRequirementResolver
{
    /** @return list<string> product type chain, root first */
    public function chain(string $productTypeCode): array
    {
        $chain = [];
        $code = strtoupper($productTypeCode);
        while ($code !== null) {
            if (in_array($code, $chain, true)) {
                throw new \LogicException("Cyclic product type inheritance at $code");
            }
            $type = DocumentProductType::where('code', $code)->first();
            if (! $type) {
                throw ValidationException::withMessages(['product_type' => "Unknown document product type $code."]);
            }
            array_unshift($chain, $code);
            $code = $type->extends_code;
        }

        return $chain;
    }

    /**
     * @return Collection<int,array<string,mixed>> resolved requirements with `source`
     *                                             (BASELINE | INHERITED:<code> | OWN | PRODUCT_OVERRIDE)
     */
    public function resolve(string $productTypeCode, ?InsuranceProduct $product = null): Collection
    {
        $chain = $this->chain($productTypeCode);
        $own = end($chain);
        $layers = array_merge([Entry::BASELINE], $chain);
        $entries = Entry::query()->active()->with('documentType')->whereIn('product_type_code', $layers)->get()->groupBy('product_type_code');

        $resolved = [];
        foreach ($layers as $layer) {
            foreach ($entries[$layer] ?? [] as $e) {
                $key = $e->stage.'|'.$e->document_type_id.'|'.$e->variant_code;
                $resolved[$key] = $this->row($e, $layer === Entry::BASELINE ? 'BASELINE' : ($layer === $own ? 'OWN' : 'INHERITED:'.$layer));
            }
        }

        if ($product) {
            $overrides = ProductDocumentRequirement::where('insurance_product_id', $product->id)->where('kind', 'MATRIX_OVERRIDE')->where('status', 'ACTIVE')->get();
            foreach ($overrides as $o) {
                $key = $o->stage.'|'.$o->document_type_id.'|'.$o->variant_code;
                if (isset($resolved[$key])) {
                    $resolved[$key]['level'] = $o->level;
                    $resolved[$key]['source'] = 'PRODUCT_OVERRIDE';
                    $resolved[$key]['override_id'] = $o->id;
                } else {
                    $t = $o->documentType;
                    $resolved[$key] = ['stage' => $o->stage, 'document_type_id' => $o->document_type_id, 'canonical_code' => $t?->canonical_code, 'label_en' => $t?->name_en, 'label_fr' => $t?->name_fr,
                        'variant_code' => $o->variant_code ?: null, 'matrix_label' => $t?->name_en, 'level' => $o->level, 'alternate_level' => null, 'insurer_overridable' => false,
                        'raw_level' => $o->level, 'qualifiers' => [], 'issuer' => $t?->issuer_authority[0] ?? null, 'recipient' => $t?->recipient, 'trigger' => $t?->generation_triggers[0] ?? null,
                        'sort_order' => 100000, 'source' => 'PRODUCT_OVERRIDE', 'override_id' => $o->id];
                }
            }
        }

        $stageOrder = array_flip(['PRE_CONTRACT', 'ISSUANCE', 'SERVICING', 'RENEWAL', 'TREATMENT', 'MOVEMENT', 'LIFECYCLE', 'CLAIM']);

        return collect(array_values($resolved))->sortBy([fn ($a, $b) => ($stageOrder[$a['stage']] ?? 99) <=> ($stageOrder[$b['stage']] ?? 99), fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']])->values();
    }

    /** Product type selected for a product version (ACTIVE PRODUCT_TYPE row), or null. */
    public function productTypeFor(InsuranceProduct $product): ?string
    {
        return ProductDocumentRequirement::where('insurance_product_id', $product->id)->where('kind', 'PRODUCT_TYPE')->where('status', 'ACTIVE')->latest('approved_at')->value('product_type_code');
    }

    private function row(Entry $e, string $source): array
    {
        return ['stage' => $e->stage, 'document_type_id' => $e->document_type_id, 'canonical_code' => $e->documentType?->canonical_code, 'label_en' => $e->documentType?->name_en,
            'label_fr' => $e->documentType?->name_fr, 'variant_code' => $e->variant_code ?: null, 'matrix_label' => $e->matrix_label, 'level' => $e->level,
            'alternate_level' => $e->alternate_level, 'insurer_overridable' => $e->insurer_overridable, 'raw_level' => $e->raw_level, 'qualifiers' => $e->qualifiers ?? [],
            'issuer' => $e->issuer, 'recipient' => $e->recipient, 'trigger' => $e->trigger, 'sort_order' => $e->sort_order, 'source' => $source];
    }
}
