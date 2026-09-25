<?php

declare(strict_types=1);

namespace App\Application\Claims\Decisions\Http;

use App\Application\Claims\ClaimReferenceCodes;
use App\Application\Claims\Decisions\ClaimDecisionService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\ClaimDecision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** REQ-CLM-012 claim decision API (ClaimDecisionService). */
final class ClaimDecisionController
{
    public function __construct(private readonly ClaimDecisionService $service) {}

    public function reasonCodes(): JsonResponse
    {
        return response()->json(['data' => DB::table('claim_decision_reason_codes')->where('status', 'ACTIVE')->orderBy('applies_to')->orderBy('code')->get()]);
    }

    public function index(string $claim): JsonResponse
    {
        return response()->json(['data' => $this->service->history($this->claim($claim))]);
    }

    public function store(Request $r, string $claim): JsonResponse
    {
        $d = $r->validate([
            'decision' => ['required', Rule::in(ClaimReferenceCodes::DECISION_INPUTS)],
            'reason_codes' => 'required|array|min:1|max:10', 'reason_codes.*' => 'string|max:64',
            'rationale' => 'required|string|min:20|max:5000',
            'heads' => 'present|array|max:10', 'heads.*.head' => ['required', Rule::in(ClaimDecisionService::HEADS)], 'heads.*.amount_minor' => 'required|integer|min:0',
        ]);

        return response()->json(['data' => $this->service->propose($this->claim($claim), $d, $r->user())], 201);
    }

    public function approve(Request $r, string $claim, string $decision): JsonResponse
    {
        return response()->json(['data' => $this->service->approve($this->decision($claim, $decision), $r->user())]);
    }

    public function returnToMaker(Request $r, string $claim, string $decision): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:2000']);

        return response()->json(['data' => $this->service->returnToMaker($this->decision($claim, $decision), $d['reason'], $r->user())]);
    }

    public function appeal(Request $r, string $claim): JsonResponse
    {
        $d = $r->validate(['reason_code' => 'required|string|max:64', 'statement' => 'required|string|min:10|max:5000']);

        return response()->json(['data' => $this->service->appeal($this->claim($claim), $d['reason_code'], $d['statement'], $r->user())], 201);
    }

    private function claim(string $id): Claim
    {
        return Claim::where('tenant_id', app(TenantContext::class)->id())->findOrFail($id);
    }

    private function decision(string $claim, string $id): ClaimDecision
    {
        return ClaimDecision::where(['id' => $id, 'claim_id' => $this->claim($claim)->id])->firstOrFail();
    }
}
