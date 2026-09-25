<?php

declare(strict_types=1);

namespace App\Application\Catalogue\Http;

use App\Application\Catalogue\ExclusionLegalTextService;
use App\Application\Catalogue\IndemnityCalculator;
use App\Application\Catalogue\ProductConfigurationService;
use App\Application\Catalogue\ProductHierarchyService;
use App\Application\Catalogue\ProductModelService;
use App\Application\Catalogue\ProductVersionSnapshot;
use App\Application\Catalogue\ProductVersionStatus;
use App\Application\Identity\CarrierScopeResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\Catalogue\CarrierProduct;
use App\Models\Catalogue\CoverageDeductible;
use App\Models\Catalogue\CoverageLimit;
use App\Models\Catalogue\ExclusionLegalText;
use App\Models\Catalogue\ProductFamily;
use App\Models\Catalogue\ProductPlan;
use App\Models\CoverageDefinition;
use App\Models\ExclusionDefinition;
use App\Models\InsuranceProduct;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Batch 5A — REQ-PRD-001…006 product model API (routes/product_model.php). */
final class ProductModelController
{
    public function __construct(
        private readonly ProductModelService $model,
        private readonly ProductConfigurationService $config,
        private readonly CarrierScopeResolver $scope,
    ) {}

    // ── Hierarchy: families & carrier products ──────────────────────────
    public function families(Request $r): JsonResponse
    {
        $q = ProductFamily::query()->orderBy('code');
        if ($r->filled('class_code')) {
            $q->where('class_code', $r->string('class_code'));
        }

        return response()->json(['data' => $q->get()]);
    }

    public function storeFamily(Request $r): JsonResponse
    {
        $d = $r->validate(['code' => 'required|string|max:64|unique:product_families,code', 'class_code' => ['required', Rule::in(ProductFamily::CLASS_CODES)],
            'insurance_class_id' => 'nullable|uuid|exists:insurance_classes,id', 'line_code' => 'nullable|string|exists:insurance_lines,code',
            'default_branch_code' => 'nullable|string|max:64', 'name' => 'required|array', 'name.en' => 'required|string|max:160', 'name.fr' => 'required|string|max:160',
            'description' => 'nullable|array']);

        return response()->json(['data' => $this->model->createFamily(array_filter($d, fn ($v) => $v !== null), $r->user())], 201);
    }

    public function carrierProducts(Request $r): JsonResponse
    {
        $q = CarrierProduct::with('family')->withCount('versions')->orderBy('code');
        if ($carrier = $this->carrierScope($r)) {
            $q->where('carrier_id', $carrier);
        } elseif ($r->filled('carrier_id')) {
            $q->where('carrier_id', $r->string('carrier_id'));
        }
        foreach (['product_family_id', 'line_code', 'status', 'customer_type'] as $f) {
            if ($r->filled($f)) {
                $q->where($f, $r->string($f));
            }
        }

        return response()->json(['data' => $q->paginate(25)]);
    }

    public function showCarrierProduct(Request $r, string $product): JsonResponse
    {
        $cp = CarrierProduct::with(['family', 'versions'])->findOrFail($product);
        $this->assertCarrier($r, $cp->carrier_id);
        $cp->versions->each(fn ($v) => $v->setAttribute('spec_status', ProductVersionStatus::fromStorage((string) $v->status)));

        return response()->json(['data' => $cp]);
    }

    public function storeCarrierProduct(Request $r): JsonResponse
    {
        $d = $r->validate($this->carrierProductRules() + ['carrier_id' => 'required|uuid|exists:carriers,id',
            'code' => ['required', 'string', 'max:64', Rule::unique('carrier_products')->where('carrier_id', $r->input('carrier_id'))],
            'line_code' => 'required|string|exists:insurance_lines,code']);
        $this->assertCarrier($r, $d['carrier_id']);

        return response()->json(['data' => $this->model->createCarrierProduct(array_filter($d, fn ($v) => $v !== null), $r->user())], 201);
    }

    public function updateCarrierProduct(Request $r, string $product): JsonResponse
    {
        $cp = CarrierProduct::findOrFail($product);
        $this->assertCarrier($r, $cp->carrier_id);
        $rules = collect($this->carrierProductRules())->map(fn ($rule) => is_string($rule) ? str_replace('required|', 'sometimes|', $rule) : $rule)->all();
        $d = $r->validate($rules + ['status' => 'sometimes|in:ACTIVE,SUSPENDED,RETIRED']);

        return response()->json(['data' => $this->model->updateCarrierProduct($cp, $d, $r->user())]);
    }

    // ── Versions ───────────────────────────────────────────────────────
    public function storeVersion(Request $r, string $product): JsonResponse
    {
        $cp = CarrierProduct::findOrFail($product);
        $this->assertCarrier($r, $cp->carrier_id);
        $d = $r->validate(['effective_from' => 'required|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from', 'name' => 'nullable|string|max:160',
            'sales_start' => 'nullable|date', 'sales_end' => 'nullable|date|after_or_equal:sales_start', 'new_business_allowed' => 'nullable|boolean',
            'renewal_allowed' => 'nullable|boolean', 'eligibility_rules' => 'nullable|array', 'regulatory_reference' => 'nullable|string|max:120',
            'base_version_id' => 'nullable|uuid']);

        return response()->json(['data' => $this->model->newVersion($cp, array_filter($d, fn ($v) => $v !== null), $r->user())], 201);
    }

    public function hierarchy(Request $r, string $version, ProductHierarchyService $hierarchy): JsonResponse
    {
        return response()->json(['data' => $hierarchy->forVersion($this->version($r, $version, false))]);
    }

    public function resolve(Request $r): JsonResponse
    {
        $d = $r->validate(['carrier_id' => 'required|uuid', 'code' => 'required|string|max:64', 'at' => 'nullable|date', 'timezone' => 'nullable|timezone']);
        $this->assertCarrier($r, $d['carrier_id']);
        $v = $this->model->resolveAt($d['carrier_id'], $d['code'], $d['at'] ?? now()->toIso8601String(), $d['timezone'] ?? 'Africa/Douala');

        return response()->json(['data' => $v->setAttribute('spec_status', ProductVersionStatus::fromStorage((string) $v->status))]);
    }

    public function transition(Request $r, string $version, string $action): JsonResponse
    {
        $v = $this->version($r, $version);
        $d = $r->validate(['reason' => 'required|string|min:10|max:2000']);
        $result = match ($action) {
            'approve' => $this->model->approve($v, $r->user(), $d['reason']),
            'suspend' => $this->model->suspend($v, $r->user(), $d['reason']),
            'reinstate' => $this->model->reinstate($v, $r->user(), $d['reason']),
            'retire' => $this->model->retire($v, $r->user(), $d['reason']),
        };

        return response()->json(['data' => $result->setAttribute('spec_status', ProductVersionStatus::fromStorage((string) $result->status))]);
    }

    public function snapshot(Request $r, string $version, ProductVersionSnapshot $snapshots): JsonResponse
    {
        $v = $this->version($r, $version, false);

        return response()->json(['data' => ['snapshot' => $v->snapshot, 'verification' => $snapshots->verify($v)]]);
    }

    // ── Plans, coverage terms, limits, deductibles ─────────────────────
    public function storePlan(Request $r, string $version): JsonResponse
    {
        $v = $this->version($r, $version);
        $d = $r->validate(['code' => ['required', 'string', 'max:32', Rule::unique('product_plans')->where('insurance_product_id', $v->id)], 'name' => 'required|array',
            'name.en' => 'required|string|max:160', 'name.fr' => 'required|string|max:160', 'description' => 'nullable|array', 'tier' => ['nullable', Rule::in(ProductPlan::TIERS)],
            'tariff_version_id' => ['nullable', 'uuid', Rule::exists('tariff_versions', 'id')->where('insurance_product_id', $v->id)],
            'pricing_reference' => 'nullable|string|max:120', 'eligibility' => 'nullable|array', 'is_default' => 'nullable|boolean', 'display_order' => 'nullable|integer|min:0',
            'coverages' => 'nullable|array', 'coverages.*.coverage_definition_id' => 'required|uuid|distinct', 'coverages.*.inclusion' => ['nullable', Rule::in(ProductConfigurationService::INCLUSIONS)]]);

        return response()->json(['data' => $this->config->addPlan($v, array_filter($d, fn ($x) => $x !== null), $r->user())], 201);
    }

    public function syncPlanCoverages(Request $r, string $plan): JsonResponse
    {
        $p = ProductPlan::findOrFail($plan);
        $this->version($r, $p->insurance_product_id);
        $d = $r->validate(['coverages' => 'required|array|min:1', 'coverages.*.coverage_definition_id' => 'required|uuid|distinct',
            'coverages.*.inclusion' => ['nullable', Rule::in(ProductConfigurationService::INCLUSIONS)]]);

        return response()->json(['data' => $this->config->syncPlanCoverages($p, $d['coverages'])]);
    }

    public function configureCoverage(Request $r, string $version, string $coverage): JsonResponse
    {
        $v = $this->version($r, $version);
        $d = $r->validate(['inclusion' => ['nullable', Rule::in(ProductConfigurationService::INCLUSIONS)], 'waiting_period_days' => 'nullable|integer|min:0|max:3650',
            'territory' => 'nullable|string|max:64', 'coverage_period' => 'nullable|string|max:32', 'display_order' => 'nullable|integer|min:0']);

        return response()->json(['data' => $this->config->configureCoverage($v, CoverageDefinition::findOrFail($coverage), array_filter($d, fn ($x) => $x !== null), $r->user())]);
    }

    public function storeLimit(Request $r, string $version): JsonResponse
    {
        $v = $this->version($r, $version);
        $d = $r->validate(['coverage_definition_id' => 'required|uuid', 'product_plan_id' => 'nullable|uuid', 'limit_type' => ['required', Rule::in(CoverageLimit::TYPES)],
            'amount_minor' => 'nullable|integer|min:0', 'percentage_bp' => 'nullable|integer|min:0|max:10000', 'currency' => 'nullable|string|size:3', 'notes' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->config->addLimit($v, array_filter($d, fn ($x) => $x !== null), $r->user())], 201);
    }

    public function storeDeductible(Request $r, string $version): JsonResponse
    {
        $v = $this->version($r, $version);
        $d = $r->validate(['coverage_definition_id' => 'required|uuid', 'product_plan_id' => 'nullable|uuid', 'deductible_type' => ['required', Rule::in(CoverageDeductible::TYPES)],
            'amount_minor' => 'nullable|integer|min:0', 'percentage_bp' => 'nullable|integer|min:0|max:10000', 'percentage_basis' => 'nullable|in:LOSS,SUM_INSURED',
            'days' => 'nullable|integer|min:0|max:3650', 'minimum_minor' => 'nullable|integer|min:0', 'maximum_minor' => 'nullable|integer|min:0|gte:minimum_minor',
            'currency' => 'nullable|string|size:3', 'notes' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->config->addDeductible($v, array_filter($d, fn ($x) => $x !== null), $r->user())], 201);
    }

    public function destroyTerm(Request $r, string $kind, string $id): JsonResponse
    {
        $term = $kind === 'limits' ? CoverageLimit::findOrFail($id) : CoverageDeductible::findOrFail($id);
        $this->version($r, $term->insurance_product_id);
        $this->config->removeTerm($term, $r->user());

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function indemnity(Request $r, string $version, IndemnityCalculator $calc): JsonResponse
    {
        $v = $this->version($r, $version, false);
        $d = $r->validate(['coverage_definition_id' => 'required|uuid', 'loss_minor' => 'required|integer|min:0', 'sum_insured_minor' => 'nullable|integer|min:0',
            'consumed_minor' => 'nullable|integer|min:0', 'plan_id' => 'nullable|uuid']);

        return response()->json(['data' => $calc->compute($v, $d['coverage_definition_id'], $d)]);
    }

    // ── Exclusions & legal texts ──────────────────────────────────────
    public function attachExclusion(Request $r, string $version): JsonResponse
    {
        $v = $this->version($r, $version);
        $d = $r->validate(['exclusion_definition_id' => 'required|uuid', 'level' => ['required', Rule::in(ProductConfigurationService::EXCLUSION_LEVELS)],
            'product_plan_id' => 'nullable|uuid', 'coverage_definition_id' => 'nullable|uuid', 'condition' => 'nullable|array', 'configuration' => 'nullable|array']);

        return response()->json(['data' => $this->config->attachExclusion($v, ExclusionDefinition::findOrFail($d['exclusion_definition_id']), $d, $r->user())], 201);
    }

    public function draftLegalText(Request $r, string $exclusion, ExclusionLegalTextService $texts): JsonResponse
    {
        $d = $r->validate(['text' => 'required|array', 'text.en' => 'required|string|max:20000', 'text.fr' => 'required|string|max:20000',
            'legal_reference' => 'nullable|string|max:255', 'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from']);

        return response()->json(['data' => $texts->draft(ExclusionDefinition::findOrFail($exclusion), $d, $r->user())], 201);
    }

    public function approveLegalText(Request $r, string $text, ExclusionLegalTextService $texts): JsonResponse
    {
        return response()->json(['data' => $texts->approve(ExclusionLegalText::findOrFail($text), $r->user())]);
    }

    public function legalTextAt(Request $r, string $exclusion, ExclusionLegalTextService $texts): JsonResponse
    {
        $d = $r->validate(['at' => 'nullable|date']);
        $e = ExclusionDefinition::with('legalTexts')->findOrFail($exclusion);

        return response()->json(['data' => ['effective' => $texts->at($e, $d['at'] ?? now()->toDateString()), 'history' => $e->legalTexts]]);
    }

    // ── helpers ───────────────────────────────────────────────────────
    private function carrierProductRules(): array
    {
        return ['product_family_id' => 'nullable|uuid|exists:product_families,id', 'name' => 'required|array', 'name.en' => 'required|string|max:160',
            'name.fr' => 'required|string|max:160', 'description' => 'nullable|array', 'description.en' => 'nullable|string|max:5000', 'description.fr' => 'nullable|string|max:5000',
            'customer_type' => ['nullable', Rule::in(CarrierProduct::CUSTOMER_TYPES)], 'currency' => 'nullable|string|size:3', 'market' => 'nullable|string|max:32'];
    }

    private function version(Request $r, string $id, bool $write = true): InsuranceProduct
    {
        $v = InsuranceProduct::findOrFail($id);
        $this->assertCarrier($r, $v->carrier_id);

        return $v;
    }

    private function carrierScope(Request $r): ?string
    {
        return $this->scope->carrierIdFor($r->user(), app(TenantContext::class)->id());
    }

    /** Insurer users only see and manage their own carrier's products; platform staff are tenant-wide. */
    private function assertCarrier(Request $r, string $carrierId): void
    {
        $scoped = $this->carrierScope($r);
        if ($scoped !== null && $scoped !== $carrierId) {
            throw new AuthorizationException('This product belongs to another insurer.');
        }
    }
}
