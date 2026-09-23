<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Agents;

use App\Application\Agents\AgentWithdrawalService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only view of the agent's own PartnerStatement history — what a
 * self-service withdrawal (see AgentWithdrawalController) draws its
 * available balance from. The latest PUBLISHED row's closing_balance_minor
 * is the amount currently withdrawable.
 */
final class AgentCommissionController
{
    public function index(Request $request, AgentWithdrawalService $service): JsonResponse
    {
        $perPage = min($request->integer('per_page', 20), 100);
        $statements = $service->statements($request->user(), app(TenantContext::class)->id(), $perPage);

        return response()->json(['data' => $statements]);
    }
}
