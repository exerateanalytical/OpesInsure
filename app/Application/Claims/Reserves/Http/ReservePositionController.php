<?php

declare(strict_types=1);

namespace App\Application\Claims\Reserves\Http;

use App\Application\Claims\Reserves\ClaimReserveService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\ClaimReserveChange;
use Illuminate\Http\JsonResponse;

/** REQ-CLM-008 — reserve position per head/coverage plus the movement history of a claim. */
final class ReservePositionController
{
    public function show(string $id, ClaimReserveService $reserves, TenantContext $tenant): JsonResponse
    {
        $claim = Claim::where(['id' => $id, 'tenant_id' => $tenant->id()])->firstOrFail();

        return response()->json(['data' => [
            'claim_id' => $claim->id, 'currency' => $claim->currency, 'total_reserve_minor' => (int) $claim->current_reserve_minor,
            'positions' => $reserves->position($claim),
            'movements' => ClaimReserveChange::where('claim_id', $claim->id)->orderBy('created_at')->get(),
        ]]);
    }
}
