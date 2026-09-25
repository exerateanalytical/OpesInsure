<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Claims;

use App\Application\Claims\Fnol\FnolService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** REQ-CLM-002 / AGT-052 — agent-assisted FNOL for a customer in the agent's book. */
final class AgentFnolController
{
    public function store(Request $request, FnolService $fnol): JsonResponse
    {
        $data = $request->validate([
            'policy_id' => 'required|uuid',
            'claimant_party_id' => 'required|uuid',
            'loss_occurred_at' => 'required|date|before_or_equal:now',
            'loss_details' => 'required|array',
            'loss_location' => 'nullable|string|max:255',
            'estimated_loss_minor' => 'nullable|integer|min:0',
            'idempotency_key' => 'required|string|min:16|max:128',
        ]);
        $claim = $fnol->submitForCustomer(app(TenantContext::class)->id(), $data, $request->user());

        return response()->json(['data' => ['id' => $claim->id, 'claim_number' => $claim->claim_number, 'status' => $claim->status, 'policy_id' => $claim->policy_id, 'claimant_party_id' => $claim->claimant_party_id]], 201);
    }
}
