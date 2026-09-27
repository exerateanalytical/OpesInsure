<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Claims;

use App\Application\Claims\Fnol\FnolService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use Illuminate\Http\JsonResponse;

/**
 * REQ-CLM-002 / AGT-052 — intermediary-assisted FNOL for a customer in the caller's book:
 * agents (POST /mobile/partner/agent/claims) and brokers (POST /mobile/partner/broker/claims, book = BookScope::bookOf).
 */
final class AgentFnolController
{
    public function store(AssistedFnolRequest $request, FnolService $fnol): JsonResponse
    {
        return self::created($fnol->submitForCustomer(app(TenantContext::class)->id(), $request->validated(), $request->user()));
    }

    public function storeForBroker(AssistedFnolRequest $request, FnolService $fnol): JsonResponse
    {
        return self::created($fnol->submitForBrokerClient(app(TenantContext::class)->id(), $request->validated(), $request->user()));
    }

    private static function created(Claim $claim): JsonResponse
    {
        return response()->json(['data' => ['id' => $claim->id, 'claim_number' => $claim->claim_number, 'status' => $claim->status, 'policy_id' => $claim->policy_id, 'claimant_party_id' => $claim->claimant_party_id]], 201);
    }
}
