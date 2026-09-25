<?php

declare(strict_types=1);

namespace App\Application\Health\ProviderClaims\Http;

use App\Application\Health\ProviderClaims\ProviderClaimPricer;
use App\Application\Health\ProviderClaims\ProviderClaimService;
use App\Application\Health\ProviderClaims\ProviderSettlementService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** REQ-HLT-003 — provider claims (cashless billing), EOB, disputes, settlement batches and provider statements. */
final class ProviderClaimController
{
    public function __construct(private readonly ProviderClaimService $svc, private readonly ProviderSettlementService $settlement, private readonly TenantContext $tenant) {}

    public function index(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->svc->list($this->tenant->id(), $r->only(['provider_id', 'status']))]);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'provider_id' => 'required|uuid', 'contract_id' => 'required|uuid', 'invoice_reference' => 'required|string|max:120', 'service_date' => 'required|date',
            'member_party_id' => 'nullable|uuid', 'member_reference' => 'nullable|string|max:120', 'policy_id' => 'nullable|uuid', 'preauth_id' => 'nullable|uuid',
            'lines' => 'required|array|min:1|max:200', 'lines.*.medical_service_id' => 'nullable|uuid', 'lines.*.provider_code' => 'nullable|string|max:64',
            'lines.*.quantity' => 'nullable|integer|min:1', 'lines.*.unit_price_minor' => 'required|integer|min:0',
        ]);

        return response()->json(['data' => $this->svc->create($this->tenant->id(), $d, $r->user()?->id)], 201);
    }

    public function show(string $claim): JsonResponse
    {
        return response()->json(['data' => $this->svc->find($this->tenant->id(), $claim)]);
    }

    public function eob(string $claim): JsonResponse
    {
        return response()->json(['data' => $this->svc->eob($this->tenant->id(), $claim)]);
    }

    public function submit(Request $r, string $claim): JsonResponse
    {
        return response()->json(['data' => $this->svc->submit($this->tenant->id(), $claim, $r->user()?->id)]);
    }

    public function review(Request $r, string $claim): JsonResponse
    {
        return response()->json(['data' => $this->svc->startReview($this->tenant->id(), $claim, $r->user()?->id)]);
    }

    public function adjudicate(Request $r, string $claim): JsonResponse
    {
        $d = $r->validate([
            'lines' => 'nullable|array', 'lines.*.line_no' => 'required|integer|min:1', 'lines.*.reject' => 'nullable|boolean',
            'lines.*.reason_code' => ['nullable', Rule::in(ProviderClaimPricer::REASONS)], 'lines.*.allowed_minor' => 'nullable|integer|min:0',
            'lines.*.explanation' => 'nullable|string|max:2000', 'note' => 'nullable|string|max:2000',
        ]);

        return response()->json(['data' => $this->svc->adjudicate($this->tenant->id(), $claim, $d['lines'] ?? [], $d['note'] ?? null, $r->user()?->id)]);
    }

    public function payable(Request $r, string $claim): JsonResponse
    {
        return response()->json(['data' => $this->svc->markPayable($this->tenant->id(), $claim, $r->user()?->id)]);
    }

    public function dispute(Request $r, string $claim): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:2000']);

        return response()->json(['data' => $this->svc->dispute($this->tenant->id(), $claim, $d['reason'], $r->user())]);
    }

    public function resolveDispute(Request $r, string $claim): JsonResponse
    {
        $d = $r->validate(['outcome' => 'required|in:REOPEN,UPHOLD', 'reason' => 'required|string|min:5|max:2000']);

        return response()->json(['data' => $this->svc->resolveDispute($this->tenant->id(), $claim, $d['outcome'], $d['reason'], $r->user()?->id)]);
    }

    public function createBatch(Request $r): JsonResponse
    {
        $d = $r->validate(['provider_id' => 'required|uuid', 'currency' => 'required|string|size:3', 'claim_ids' => 'nullable|array', 'claim_ids.*' => 'uuid']);

        return response()->json(['data' => $this->settlement->createBatch($this->tenant->id(), $d['provider_id'], $d['currency'], $d['claim_ids'] ?? null, $r->user()?->id)], 201);
    }

    public function showBatch(string $batch): JsonResponse
    {
        return response()->json(['data' => $this->settlement->batch($this->tenant->id(), $batch)]);
    }

    public function payBatch(Request $r, string $batch): JsonResponse
    {
        $d = $r->validate(['payment_reference' => 'required|string|max:120']);

        return response()->json(['data' => $this->settlement->payBatch($this->tenant->id(), $batch, $d['payment_reference'], $r->user()?->id)]);
    }

    public function statement(Request $r, string $provider): JsonResponse
    {
        $d = $r->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);

        return response()->json(['data' => $this->settlement->statement($this->tenant->id(), $provider, $d['from'], $d['to'])]);
    }
}
