<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Sync;

use App\Application\Sync\SyncOperationDispatchService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Generic offline-sync surface — see App\Application\Sync\SyncOperationDispatchService
 * for the allowlist design and why this is not agent-namespaced even though
 * agent client intake is currently its only wired operation.
 */
final class SyncController
{
    public function status(SyncOperationDispatchService $service): JsonResponse
    {
        return response()->json(['data' => $service->status()]);
    }

    public function dispatch(Request $request, SyncOperationDispatchService $service): JsonResponse
    {
        $envelope = $request->validate([
            'id' => 'required|uuid',
            'kind' => 'required|in:DRAFT,MUTATION,UPLOAD',
            'resource' => 'required|string|max:64',
            'resource_id' => 'nullable|uuid',
            'method' => 'required|in:POST,PUT,PATCH',
            'path' => 'required|string|max:190',
            'payload' => 'required|array',
        ]);

        $result = $service->dispatch($envelope, $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => $result]);
    }
}
