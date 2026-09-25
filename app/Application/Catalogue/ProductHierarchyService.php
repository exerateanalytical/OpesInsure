<?php

declare(strict_types=1);

namespace App\Application\Catalogue;

use App\Application\DocumentCatalogue\DocumentCatalogueService;
use App\Models\InsuranceProduct;
use App\Models\Regulatory\ProductRegulatoryMapping;
use App\Models\Regulatory\RegulatoryBranch;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PRD-002 read model — the full chain for one version:
 * CIMA branch(es) (Regulatory module mappings) → class → family → carrier product
 * → version → plans → coverages (terms, limits, deductibles) → exclusions,
 * plus the document requirements resolved by the DocumentCatalogueService.
 */
final class ProductHierarchyService
{
    public function __construct(private readonly DocumentCatalogueService $documents) {}

    public function forVersion(InsuranceProduct $v): array
    {
        $v->loadMissing(['carrierProduct.family.insuranceClass', 'carrier.party', 'coverageDefinitions', 'plans.coverages', 'limits', 'deductibles']);
        $cp = $v->carrierProduct;
        $family = $cp?->family;
        $mappings = ProductRegulatoryMapping::where('insurance_product_id', $v->id)->whereIn('status', ['ACTIVE', 'PENDING_APPROVAL'])->get();
        $branchCodes = $mappings->pluck('branch_code')->push($family?->default_branch_code)->filter()->unique()->values();
        $branches = RegulatoryBranch::current()->whereIn('code', $branchCodes)->get()->keyBy('code');

        return [
            'cima_branches' => $branchCodes->map(fn ($code) => [
                'code' => $code, 'label_en' => $branches[$code]->label_en ?? null, 'label_fr' => $branches[$code]->label_fr ?? null,
                'mapping' => ($m = $mappings->firstWhere('branch_code', $code)) ? ['id' => $m->id, 'relationship_type' => $m->relationship_type, 'status' => $m->status, 'source' => $m->source] : null,
                'is_family_default' => $code === $family?->default_branch_code,
            ])->all(),
            'class' => $family ? ['code' => $family->class_code, 'register_class' => $family->insuranceClass?->only(['id', 'code', 'branch', 'name'])] : null,
            'family' => $family?->only(['id', 'code', 'name', 'line_code', 'default_branch_code', 'status']),
            'carrier_product' => $cp?->only(['id', 'carrier_id', 'code', 'line_code', 'name', 'description', 'customer_type', 'currency', 'market', 'status']),
            'version' => $v->only(['id', 'version', 'name', 'effective_from', 'effective_until', 'sales_start', 'sales_end', 'new_business_allowed', 'renewal_allowed',
                'regulatory_reference', 'snapshot_hash', 'base_version_id']) + ['status' => ProductVersionStatus::fromStorage((string) $v->status), 'storage_status' => $v->status],
            'coverages' => $v->coverageDefinitions->sortBy('pivot.display_order')->map(fn ($c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'inclusion' => $c->pivot->inclusion, 'waiting_period_days' => $c->pivot->waiting_period_days,
                'territory' => $c->pivot->territory, 'coverage_period' => $c->pivot->coverage_period,
                'limits' => $v->limits->where('coverage_definition_id', $c->id)->values(), 'deductibles' => $v->deductibles->where('coverage_definition_id', $c->id)->values(),
            ])->values()->all(),
            'plans' => $v->plans->map(fn ($p) => $p->only(['id', 'code', 'name', 'tier', 'is_default', 'tariff_version_id', 'pricing_reference', 'status'])
                + ['coverages' => $p->coverages->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'inclusion' => $c->pivot->inclusion])->all()])->all(),
            'exclusions' => DB::table('product_exclusions as pe')->join('exclusion_definitions as e', 'e.id', '=', 'pe.exclusion_definition_id')
                ->where('pe.insurance_product_id', $v->id)->get(['pe.id', 'e.id as exclusion_definition_id', 'e.code', 'e.name', 'e.kind', 'pe.level', 'pe.product_plan_id', 'pe.coverage_definition_id'])
                ->map(fn ($e) => ['name' => json_decode((string) $e->name, true)] + (array) $e)->all(),
            'document_requirements' => rescue(fn () => $this->documents->requirementsFor($v)->values()->all(), [], false),
        ];
    }
}
