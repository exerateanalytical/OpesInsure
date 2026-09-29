<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace\PartnerAgentServicingController as S;
use Illuminate\Support\Facades\Route;

/*
 | Agent servicing (launch 2026-10-02, web /account agent pages AGT-037..056). Included from routes/api.php inside
 | the auth:api + tenant + json.api group, next to wave16_partner.php. Reads need agent.clients.read, writes the
 | permission of the underlying service; every row is bounded by the agent's own book (AgentServicingQuery).
 */
Route::get('mobile/partner/agent/policies/{policy}', [S::class, 'policy'])->middleware('permission:agent.clients.read')->whereUuid('policy');
Route::post('mobile/partner/agent/policies/{policy}/service-requests', [S::class, 'requestService'])->middleware(['permission:agent.clients.manage', 'throttle:10,1'])->whereUuid('policy');
Route::post('mobile/partner/agent/policies/{policy}/cancellations/preview', [S::class, 'previewCancellation'])->middleware(['permission:policies.cancellation.request', 'throttle:30,1'])->whereUuid('policy');
Route::post('mobile/partner/agent/policies/{policy}/cancellations', [S::class, 'requestCancellation'])->middleware(['permission:policies.cancellation.request', 'throttle:10,1'])->whereUuid('policy');
Route::post('mobile/partner/agent/policies/{policy}/sticker', [S::class, 'assignSticker'])->middleware(['permission:stickers.assign', 'throttle:20,1'])->whereUuid('policy');
Route::get('mobile/partner/agent/service-requests', [S::class, 'serviceRequests'])->middleware('permission:agent.clients.read');
Route::get('mobile/partner/agent/service-requests/{id}', [S::class, 'serviceRequest'])->middleware('permission:agent.clients.read')->whereUuid('id');
Route::get('mobile/partner/agent/payments', [S::class, 'payments'])->middleware('permission:agent.clients.read');
Route::get('mobile/partner/agent/clients/{customer}/payments', [S::class, 'clientPayments'])->middleware('permission:agent.clients.read')->whereUuid('customer');
Route::get('mobile/partner/agent/clients/{customer}/vehicles', [S::class, 'clientVehicles'])->middleware('permission:agent.clients.read')->whereUuid('customer');
Route::post('mobile/partner/agent/clients/{customer}/vehicles', [S::class, 'registerVehicle'])->middleware(['permission:agent.clients.manage', 'throttle:20,1'])->whereUuid('customer');
Route::get('mobile/partner/agent/vehicles/{asset}', [S::class, 'vehicle'])->middleware('permission:agent.clients.read')->whereUuid('asset');
Route::get('mobile/partner/agent/stickers', [S::class, 'stickers'])->middleware('permission:stickers.view');
Route::get('mobile/partner/agent/claims/{claim}', [S::class, 'claim'])->middleware('permission:agent.clients.read')->whereUuid('claim');
