<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Agents;

use App\Application\Agents\AgentWithdrawalService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentWithdrawalController
{
    public function index(Request $request, AgentWithdrawalService $service): JsonResponse
    {
        $perPage = min($request->integer('per_page', 20), 100);
        $withdrawals = $service->withdrawals($request->user(), app(TenantContext::class)->id(), $perPage);

        return response()->json(['data' => $withdrawals]);
    }

    public function store(Request $request, AgentWithdrawalService $service): JsonResponse
    {
        $data = $request->validate(AgentWithdrawalService::rules());
        $idempotencyKey = (string) $request->header('Idempotency-Key');
        $payout = $service->request($data, $request->user(), app(TenantContext::class)->id(), $idempotencyKey);

        return response()->json(['data' => $payout], 201);
    }
}
