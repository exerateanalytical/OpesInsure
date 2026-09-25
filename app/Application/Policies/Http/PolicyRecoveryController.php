<?php

declare(strict_types=1);

namespace App\Application\Policies\Http;

use App\Application\Policies\Lapse\PolicyRecoveryService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Policy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * REQ-POL-010 — premium recovery of a SUSPENDED / EXPIRED / LAPSED policy. Every case and instalment is
 * resolved inside the caller's tenant before the (tenant-agnostic) service is called; foreign ids are 404.
 */
final class PolicyRecoveryController
{
    public function __construct(private readonly PolicyRecoveryService $recovery) {}

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    private function caseId(string $id): string
    {
        abort_unless(DB::table('policy_recovery_cases')->where('tenant_id', $this->tenant())->where('id', $id)->exists(), 404);

        return $id;
    }

    private function instalmentId(string $id): string
    {
        abort_unless(DB::table('policy_premium_instalments')->where('tenant_id', $this->tenant())->where('id', $id)->exists(), 404);

        return $id;
    }

    public function open(Request $r, string $policy): JsonResponse
    {
        $d = $r->validate(['reason_code' => 'required|string|max:64', 'new_coverage_ends_at' => 'nullable|date', 'notes' => 'nullable|string|max:2000']);
        $p = Policy::where('tenant_id', $this->tenant())->findOrFail($policy);

        return response()->json(['data' => $this->recovery->open($p, $d, $r->user())], 201);
    }

    public function index(Request $r): JsonResponse
    {
        $status = $r->query('status', 'OPEN');
        $rows = DB::table('policy_recovery_cases')->where('tenant_id', $this->tenant())
            ->when(is_string($status), fn ($q) => $q->where('status', $status))->orderBy('created_at')->limit(200)->get();

        return response()->json(['data' => $rows]);
    }

    public function show(string $case): JsonResponse
    {
        return response()->json(['data' => DB::table('policy_recovery_cases')->where('id', $this->caseId($case))->first()]);
    }

    /** Staff posting of a reconciled payment against an instalment (idempotent per payment intent). */
    public function settleInstalment(Request $r, string $instalment): JsonResponse
    {
        $d = $r->validate(['amount_minor' => 'required|integer|min:1', 'payment_intent_id' => 'nullable|uuid']);

        return response()->json(['data' => $this->recovery->settleInstalment($this->instalmentId($instalment), (int) $d['amount_minor'], $d['payment_intent_id'] ?? null)]);
    }

    public function waiveInstalment(Request $r, string $instalment): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return response()->json(['data' => $this->recovery->waiveInstalment($this->instalmentId($instalment), $d['reason'], $r->user())]);
    }

    public function approve(Request $r, string $case): JsonResponse
    {
        $d = $r->validate(['new_coverage_ends_at' => 'nullable|date']);

        return response()->json(['data' => $this->recovery->approve($this->caseId($case), $r->user(), $d['new_coverage_ends_at'] ?? null)]);
    }

    public function reject(Request $r, string $case): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return response()->json(['data' => $this->recovery->reject($this->caseId($case), $r->user(), $d['reason'])]);
    }
}
