<?php

declare(strict_types=1);

namespace App\Application\Claims\Settlement\Http;

use App\Application\Claims\Settlement\ClaimSettlementService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Batch 12 C13: REQ-CLM-013 claim settlement (calculate, offer, accept/dispute, discharge, payment). */
final class ClaimSettlementController
{
    public function __construct(private TenantContext $tenant, private ClaimSettlementService $service) {}

    public function calculate(Request $r, string $claim): JsonResponse
    {
        $d = $r->validate([
            'covered_minor' => 'required|integer|min:0', 'excluded_minor' => 'nullable|integer|min:0', 'deductible_minor' => 'nullable|integer|min:0',
            'coverage_code' => 'nullable|string|max:64', 'payee_party_id' => 'nullable|uuid',
            'adjustments' => 'nullable|array|max:50', 'adjustments.*.code' => 'required|string|max:64', 'adjustments.*.amount_minor' => 'required|integer',
            'adjustments.*.reason' => 'nullable|string|max:1000',
        ]);
        $c = Claim::where('tenant_id', $this->tenant->id())->whereKey($claim)->firstOrFail();

        return $this->ok($this->service->calculate($c, $d, $r->user()), 201);
    }

    public function show(string $settlement): JsonResponse
    {
        $s = $this->service->owned($settlement);
        $events = DB::table('claim_settlement_events')->where('claim_settlement_id', $s->id)->orderBy('occurred_at')->get();

        return response()->json(['data' => $this->service->present($s) + ['history' => $events]]);
    }

    public function offer(Request $r, string $settlement): JsonResponse
    {
        return $this->ok($this->service->offer($settlement, $r->user()));
    }

    public function accept(Request $r, string $settlement): JsonResponse
    {
        return $this->ok($this->service->accept($settlement, $r->user()));
    }

    public function dispute(Request $r, string $settlement): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return $this->ok($this->service->dispute($settlement, $d['reason'], $r->user()));
    }

    public function requestDischarge(Request $r, string $settlement): JsonResponse
    {
        $d = $r->validate(['signer_user_id' => 'nullable|uuid']);

        return $this->ok($this->service->requestDischarge($settlement, $r->user(), $d['signer_user_id'] ?? null));
    }

    public function confirmDischarge(Request $r, string $settlement): JsonResponse
    {
        return $this->ok($this->service->confirmDischarge($settlement, $r->user()));
    }

    public function requestPayment(Request $r, string $settlement): JsonResponse
    {
        return $this->ok($this->service->requestPayment($settlement, $r->user()));
    }

    private function ok(object $s, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->service->present($s)], $status);
    }
}
