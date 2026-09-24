<?php

declare(strict_types=1);

namespace App\Application\Regulatory;

use App\Models\Carrier;
use App\Models\InsuranceProduct;
use App\Models\Regulatory\InsurerRegulatoryAuthorization;
use App\Models\Regulatory\LegalReference;
use App\Models\Regulatory\MicroinsuranceBranch;
use App\Models\Regulatory\ProductRegulatoryMapping;
use App\Models\Regulatory\RegulatoryBranch;
use App\Models\Regulatory\RegulatoryReportingCategory;
use App\Models\Regulatory\RegulatoryTerm;

/** PLT-CIMA-001 dashboard figures and the onboarding gap lists. Read-only; never mutates. */
final class CimaComplianceReport
{
    public function __construct(private readonly CimaPublicationGuard $guard) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $products = InsuranceProduct::with('carrier')->orderBy('code')->get();
        $mapped = ProductRegulatoryMapping::where('status', 'ACTIVE')->where('relationship_type', 'PRIMARY')->distinct()->pluck('insurance_product_id')->flip();
        $unmapped = [];
        $blocked = [];
        foreach ($products as $p) {
            $row = ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'line_code' => $p->line_code, 'status' => $p->status, 'carrier' => $this->carrierName($p->carrier)];
            if (! isset($mapped[$p->id])) {
                $unmapped[] = $row;

                continue;
            }
            $violations = $this->guard->violations($p, false);
            if ($violations !== []) {
                $blocked[] = $row + ['reasons' => $violations, 'grandfathered' => in_array($p->status, ['ACTIVE', 'RETIRED'], true) && $this->guard->isGrandfathered($p)];
            }
        }
        $authorized = InsurerRegulatoryAuthorization::where('status', 'ACTIVE')->distinct()->pluck('carrier_id')->flip();
        $withoutAuth = Carrier::orderBy('legal_name')->get()->reject(fn (Carrier $c) => isset($authorized[$c->id]))
            ->map(fn (Carrier $c) => ['id' => $c->id, 'name' => $this->carrierName($c), 'canonical_id' => $c->canonical_id, 'is_demo' => (bool) $c->is_demo, 'products' => $products->where('carrier_id', $c->id)->count()])
            ->values()->all();

        return [
            'counts' => [
                'branches' => RegulatoryBranch::current()->count(),
                'reserved_branches' => RegulatoryBranch::current()->where('reserved', true)->count(),
                'micro_branches' => MicroinsuranceBranch::where('status', 'ACTIVE')->count(),
                'reporting_categories' => RegulatoryReportingCategory::where('kind', 'ART_411_CATEGORY')->where('status', 'ACTIVE')->count(),
                'intermediary_measures' => RegulatoryReportingCategory::where('kind', 'ART_557_MEASURE')->where('status', 'ACTIVE')->count(),
                'terms' => RegulatoryTerm::where('namespace', 'CIMA_TERM')->where('status', 'ACTIVE')->count(),
                'legal_references' => LegalReference::where('status', 'ACTIVE')->count(),
                'products' => $products->count(),
                'products_unmapped' => count($unmapped),
                'products_blocked' => count($blocked),
                'insurers_without_authorization' => count($withoutAuth),
                'pending_mappings' => ProductRegulatoryMapping::where('status', 'PENDING_APPROVAL')->count(),
                'pending_authorizations' => InsurerRegulatoryAuthorization::where('status', 'PENDING_APPROVAL')->count(),
            ],
            'unmapped_products' => $unmapped,
            'blocked_products' => $blocked,
            'insurers_without_authorization' => $withoutAuth,
        ];
    }

    private function carrierName(?Carrier $c): ?string
    {
        return $c ? ($c->trade_name ?: $c->legal_name ?: $c->party?->display_name ?: $c->cima_code) : null;
    }
}
