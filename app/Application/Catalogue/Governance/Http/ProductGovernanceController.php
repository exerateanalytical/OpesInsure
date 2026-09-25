<?php

declare(strict_types=1);

namespace App\Application\Catalogue\Governance\Http;

use App\Application\Catalogue\Governance\Models\ProductTestCase;
use App\Application\Catalogue\Governance\Models\ProductTestRun;
use App\Application\Catalogue\Governance\ProductCompletenessService;
use App\Application\Catalogue\Governance\ProductGovernanceService;
use App\Application\Catalogue\ProductVersionStatus;
use App\Application\Catalogue\Sandbox\ProductSandbox;
use App\Application\Identity\CarrierScopeResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\InsuranceProduct;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Batch 6A — REQ-PRD-007/008/010 governance, completeness and sandbox API (routes/product_governance.php). */
final class ProductGovernanceController
{
    /** Permission required to move a version OUT of each stage (route middleware only checks catalogue.view). */
    private const ADVANCE_PERMISSION = ['DRAFT' => 'catalogue.manage', 'CONFIGURATION' => 'catalogue.manage', 'TECHNICAL_REVIEW' => 'catalogue.review',
        'COMPLIANCE_REVIEW' => 'catalogue.review', 'BUSINESS_APPROVAL' => 'catalogue.publish', 'SANDBOX_TESTS' => 'catalogue.test', 'READY' => 'catalogue.publish'];

    public function __construct(
        private readonly ProductGovernanceService $governance,
        private readonly ProductCompletenessService $completeness,
        private readonly ProductSandbox $sandbox,
        private readonly CarrierScopeResolver $scope,
    ) {}

    public function show(Request $r, string $version): JsonResponse
    {
        $v = $this->version($r, $version);

        return response()->json(['data' => [
            'version_id' => $v->id, 'status' => ProductVersionStatus::fromStorage((string) $v->status), 'storage_status' => $v->status,
            'governance' => $this->governance->state($v), 'next_stage' => ProductGovernanceService::NEXT[$this->governance->state($v)->stage] ?? null,
            'history' => $this->governance->history($v),
        ]]);
    }

    public function update(Request $r, string $version): JsonResponse
    {
        $v = $this->version($r, $version);
        $d = $r->validate(['owner_user_id' => 'sometimes|nullable|uuid|exists:users,id', 'target_market' => 'sometimes|array', 'target_market.*' => 'string|max:120',
            'prohibited_market' => 'sometimes|array', 'prohibited_market.*' => 'string|max:120', 'next_review_date' => 'sometimes|nullable|date']);

        return response()->json(['data' => $this->governance->updateAttributes($v, $d, $r->user())]);
    }

    public function advance(Request $r, string $version): JsonResponse
    {
        $v = $this->version($r, $version);
        $d = $r->validate(['notes' => 'nullable|string|max:2000']);
        $this->require($r, self::ADVANCE_PERMISSION[$this->governance->state($v)->stage] ?? 'catalogue.view'); // terminal stages: the service answers 422

        return response()->json(['data' => $this->governance->advance($v, $r->user(), (string) ($d['notes'] ?? ''))]);
    }

    public function reject(Request $r, string $version): JsonResponse
    {
        $v = $this->version($r, $version);
        $d = $r->validate(['reason' => 'required|string|min:10|max:2000']);
        $stage = $this->governance->state($v)->stage;
        $this->require($r, in_array($stage, ['BUSINESS_APPROVAL', 'SANDBOX_TESTS', 'READY'], true) ? 'catalogue.publish' : 'catalogue.review');

        return response()->json(['data' => $this->governance->reject($v, $r->user(), $d['reason'])]);
    }

    public function publish(Request $r, string $version): JsonResponse
    {
        $v = $this->version($r, $version);
        $d = $r->validate(['reason' => 'nullable|string|max:2000', 'publish_at' => 'nullable|date']);

        return response()->json(['data' => $this->governance->publish($v, $r->user(), (string) ($d['reason'] ?? ''), $d['publish_at'] ?? null)]);
    }

    public function completeness(Request $r, string $version): JsonResponse
    {
        return response()->json(['data' => $this->completeness->evaluate($this->version($r, $version))]);
    }

    public function diff(Request $r, string $version): JsonResponse
    {
        $v = $this->version($r, $version);
        $against = $r->filled('against') ? $this->version($r, (string) $r->string('against')) : null;
        if ($against && $against->carrier_product_id !== $v->carrier_product_id) {
            abort(422, 'Both versions must belong to the same carrier product.');
        }

        return response()->json(['data' => $this->governance->diff($v, $against)]);
    }

    public function testCases(Request $r, string $version): JsonResponse
    {
        $v = $this->version($r, $version);

        return response()->json(['data' => ProductTestCase::where('insurance_product_id', $v->id)->orderBy('code')->get()]);
    }

    public function storeTestCase(Request $r, string $version): JsonResponse
    {
        $v = $this->version($r, $version);
        $d = $r->validate(['code' => 'required|string|max:64|regex:/^[A-Z0-9_\-]+$/', 'name' => 'required|string|max:191', 'facts' => 'required|array',
            'expected' => 'nullable|array', 'expected.eligibility' => ['nullable', Rule::in(['ELIGIBLE', 'INELIGIBLE', 'CONDITIONAL', 'REFER_TO_UNDERWRITING', 'MORE_INFORMATION_REQUIRED'])],
            'expected.premium_total_minor' => 'nullable|integer|min:0', 'expected.premium_min_minor' => 'nullable|integer|min:0', 'expected.premium_max_minor' => 'nullable|integer|min:0',
            'expected.rating_fails' => 'nullable|boolean', 'reference_date' => 'nullable|date']);

        return response()->json(['data' => $this->sandbox->addCase($v, $d, $r->user())], 201);
    }

    public function destroyTestCase(Request $r, string $case): JsonResponse
    {
        $c = ProductTestCase::findOrFail($case);
        $this->version($r, $c->insurance_product_id);
        $c->delete();

        return response()->json(null, 204);
    }

    /** Ad-hoc sandbox evaluation: nothing persisted. */
    public function sandbox(Request $r, string $version): JsonResponse
    {
        $v = $this->version($r, $version);
        $d = $r->validate(['facts' => 'required|array', 'reference_date' => 'nullable|date', 'expected' => 'nullable|array']);

        return response()->json(['data' => $this->sandbox->evaluate($v, $d['facts'], $d['reference_date'] ?? null, $d['expected'] ?? [])]);
    }

    public function runTests(Request $r, string $version): JsonResponse
    {
        $v = $this->version($r, $version);

        return response()->json(['data' => $this->sandbox->runPack($v, $r->user())], 201);
    }

    public function testRuns(Request $r, string $version): JsonResponse
    {
        $v = $this->version($r, $version);
        $latest = $this->sandbox->latestRun($v);

        return response()->json(['data' => ProductTestRun::where('insurance_product_id', $v->id)->orderByDesc('ran_at')->limit(20)->get(),
            'meta' => ['latest_is_current' => $latest['current'] ?? null]]);
    }

    private function require(Request $r, string $permission): void
    {
        if (! $r->user()->hasPermission($permission)) {
            throw new AuthorizationException("Missing permission {$permission} for this governance step.");
        }
    }

    private function version(Request $r, string $id): InsuranceProduct
    {
        $v = InsuranceProduct::findOrFail($id);
        $scoped = $this->scope->carrierIdFor($r->user(), app(TenantContext::class)->id());
        if ($scoped !== null && $scoped !== $v->carrier_id) {
            throw new AuthorizationException('This product belongs to another insurer.');
        }

        return $v;
    }
}
