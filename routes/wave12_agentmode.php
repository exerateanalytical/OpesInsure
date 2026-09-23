<?php

use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileAgentPortalController;
use App\Interfaces\Http\Controllers\Api\V1\Agents\AgentClientController;
use App\Interfaces\Http\Controllers\Api\V1\Agents\AgentCommissionController;
use App\Interfaces\Http\Controllers\Api\V1\Agents\AgentOfflineQueueController;
use App\Interfaces\Http\Controllers\Api\V1\Agents\AgentWithdrawalController;
use App\Interfaces\Http\Controllers\Api\V1\Sync\SyncController;
use Illuminate\Support\Facades\Route;

// Agent Mode — client intake, self-service commission withdrawal, and the
// generic offline-queue dispatch endpoint. Required from routes/api.php
// inside the existing ['auth:api', 'tenant', 'json.api'] group, so every
// route below already carries those three. Every mutating action here also
// carries the agent-specific 'agent.*' permission (see config/permissions.php)
// on top of the AgentPartnerResolver ownership scoping baked into each
// service — the Patch 4 merge guide requires "the freelance-agent role and
// precise permission for every endpoint".

// Client intake — see App\Application\Agents\AgentClientIntakeService.
Route::get('mobile/agent/clients', [MobileAgentPortalController::class, 'clients'])->middleware('permission:agent.clients.read');
Route::get('mobile/agent/clients/{customer}', [MobileAgentPortalController::class, 'client'])->middleware('permission:agent.clients.read');
Route::post('mobile/agent/clients', [MobileAgentPortalController::class, 'createClient'])
    ->middleware(['permission:agent.clients.manage', 'idempotency:mobile.agent.clients.store', 'throttle:10,1']);

// Commissions / self-service withdrawal — see App\Application\Agents\AgentWithdrawalService.
Route::get('mobile/agent/commissions', [MobileAgentPortalController::class, 'commissions'])->middleware('permission:agent.commissions.read');
Route::get('mobile/agent/withdrawals', [MobileAgentPortalController::class, 'withdrawals'])->middleware('permission:agent.withdrawals.read');
Route::post('mobile/agent/withdrawals', [MobileAgentPortalController::class, 'requestWithdrawal'])
    ->middleware(['permission:agent.withdrawals.request', 'idempotency:mobile.agent.withdrawals.store', 'throttle:5,1']);

// Offline queue — the agent app's own view of what it queued via
// POST /mobile/sync/operations below, plus an explicit retry.
Route::get('mobile/agent/offline-queue', [MobileAgentPortalController::class, 'offlineQueue'])->middleware('permission:agent.sync.read');
Route::post('mobile/agent/offline-queue/{operation}/retry', [MobileAgentPortalController::class, 'retryOffline'])
    ->middleware(['permission:agent.sync.retry', 'throttle:20,1']);

// Generic offline-sync dispatch (App\Application\Sync\SyncOperationDispatchService).
// /sync/status is intentionally left without a permission gate: it returns
// nothing sensitive (server time, minimum supported client version) and,
// unlike the agent.* endpoints above, is not agent-specific — any
// authenticated mobile client may poll it before deciding whether to queue
// operations at all.
Route::get('mobile/sync/status', [SyncController::class, 'status']);
Route::post('mobile/sync/operations', [SyncController::class, 'dispatch'])
    ->middleware(['permission:agent.sync.dispatch', 'idempotency:mobile.sync.operations.dispatch', 'throttle:30,1']);
