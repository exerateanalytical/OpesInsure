<?php

declare(strict_types=1);

namespace App\Application\Health\Benefits\Http;

use App\Application\Health\Benefits\BenefitAccumulator;
use App\Application\Health\Benefits\BenefitRefused;
use App\Application\Health\Benefits\BenefitSchedule;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Batch 14 E5 — REQ-HLT-004 benefit schedules + remaining-benefit API. */
final class BenefitController
{
    public function __construct(private TenantContext $tenant, private BenefitAccumulator $accumulator, private BenefitSchedule $schedules) {}

    /** GET v1/health/benefits/remaining?member_ref=&benefit_code=&at=&policy_id=&family_ref=&insurance_product_id=&product_plan_id= */
    public function remaining(Request $r): JsonResponse
    {
        $d = $r->validate([
            'member_ref' => 'required|string|max:128', 'benefit_code' => 'required|string|max:64', 'at' => 'nullable|date',
            'policy_id' => 'nullable|uuid', 'family_ref' => 'nullable|string|max:128', 'insurance_product_id' => 'nullable|uuid', 'product_plan_id' => 'nullable|uuid',
            'gross_minor' => 'nullable|integer|min:0',
        ]);
        $member = array_filter(array_intersect_key($d, array_flip(['member_ref', 'policy_id', 'family_ref', 'insurance_product_id', 'product_plan_id'])), fn ($v) => $v !== null);
        $at = isset($d['at']) ? Carbon::parse($d['at']) : now();
        try {
            $out = $this->accumulator->remaining($this->tenant->id(), $member, $d['benefit_code'], $at, true);
            if (isset($d['gross_minor'])) {
                $out['adjudication'] = $this->accumulator->adjudicate($this->tenant->id(), $member, $d['benefit_code'], (int) $d['gross_minor'], $at);
            }
        } catch (BenefitRefused $e) {
            return response()->json(['message' => $e->getMessage(), 'reason_code' => $e->reasonCode], 422);
        }

        return response()->json(['data' => $out]);
    }

    /** GET v1/health/benefit-schedules?insurance_product_id= */
    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['insurance_product_id' => 'nullable|uuid']);
        $rows = DB::table('health_benefit_schedules')->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $this->tenant->id()))
            ->when($d['insurance_product_id'] ?? null, fn ($q, $p) => $q->where('insurance_product_id', $p))
            ->orderBy('benefit_code')->orderBy('effective_from')->get();

        return response()->json(['data' => $rows]);
    }

    /** POST v1/health/benefit-schedules — a tenant-scoped schedule line. */
    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'insurance_product_id' => 'required|uuid|exists:insurance_products,id', 'product_plan_id' => 'nullable|uuid|exists:product_plans,id',
            'coverage_definition_id' => 'nullable|uuid|exists:coverage_definitions,id', 'benefit_code' => 'required|string|max:64|regex:/^[A-Z0-9_.-]+$/',
            'name' => 'nullable|array', 'parent_schedule_id' => 'nullable|uuid|exists:health_benefit_schedules,id',
            'period_basis' => ['nullable', Rule::in(BenefitSchedule::PERIODS)], 'scope' => ['nullable', Rule::in(BenefitSchedule::SCOPES)],
            'period_limit_minor' => 'nullable|integer|min:0', 'family_limit_minor' => 'nullable|integer|min:0', 'per_event_limit_minor' => 'nullable|integer|min:0',
            'per_visit_limit_minor' => 'nullable|integer|min:0', 'max_visits_per_period' => 'nullable|integer|min:1', 'copay_bp' => 'nullable|integer|min:0|max:10000',
            'waiting_period_days' => 'nullable|integer|min:0', 'currency' => 'nullable|string|size:3', 'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after:effective_from',
        ]);

        return response()->json(['data' => $this->schedules->create(['tenant_id' => $this->tenant->id()] + $d, $r->user()?->id)], 201);
    }
}
