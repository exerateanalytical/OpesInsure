<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Agents;

use App\Application\Sync\SyncOperationDispatchService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The agent app's own view of what it queued via POST /mobile/sync/operations
 * while offline (see App\Application\Sync\SyncOperationDispatchService) and
 * an explicit, user-triggered retry for one that was rejected.
 */
final class AgentOfflineQueueController
{
    public function index(Request $request, SyncOperationDispatchService $service): JsonResponse
    {
        $perPage = min($request->integer('per_page', 20), 100);
        $queue = $service->list($request->user(), app(TenantContext::class)->id(), $perPage);

        return response()->json(['data' => $queue]);
    }

    public function retry(Request $request, string $operation, SyncOperationDispatchService $service): JsonResponse
    {
        $result = $service->retry($operation, $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => $result]);
    }
}
