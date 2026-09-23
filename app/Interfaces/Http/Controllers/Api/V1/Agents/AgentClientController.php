<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Agents;

use App\Application\Agents\AgentClientIntakeService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Agent-mode client intake — see App\Application\Agents\AgentClientIntakeService
 * for why this does not reuse the staff-facing CustomerController directly.
 */
final class AgentClientController
{
    public function index(Request $request, AgentClientIntakeService $service): JsonResponse
    {
        $perPage = min($request->integer('per_page', 20), 100);
        $clients = $service->list($request->user(), app(TenantContext::class)->id(), $perPage);

        return response()->json(['data' => $clients]);
    }

    public function show(Request $request, string $customer, AgentClientIntakeService $service): JsonResponse
    {
        $client = $service->show($customer, $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => $client]);
    }

    public function store(Request $request, AgentClientIntakeService $service): JsonResponse
    {
        $data = $request->validate(AgentClientIntakeService::rules());
        $result = $service->register($data, $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => $result], 201);
    }
}
