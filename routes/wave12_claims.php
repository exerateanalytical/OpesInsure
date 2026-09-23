<?php

use App\Interfaces\Http\Controllers\Api\V1\Claims\MobileClaimController;
use App\Interfaces\Http\Controllers\Api\V1\Claims\MobileClaimEvidenceController;
use App\Interfaces\Http\Controllers\Api\V1\Claims\MobileClaimPartyController;
use Illuminate\Support\Facades\Route;

// Customer-facing "my claims" front door onto the existing staff Claims
// machinery (ClaimLifecycleService/ClaimStateMachine/ClaimEvidenceService —
// see app/Application/Claims). Kept in its own file, required from
// routes/api.php alongside wave12.php, rather than folded into it, since
// this is a distinct capability (claims) from that file's generic
// uploads/idempotency infrastructure.
Route::get('mobile/claims', [MobileClaimController::class, 'index']);
Route::post('mobile/claims', [MobileClaimController::class, 'store'])->middleware(['idempotency:mobile.claims.fnol', 'throttle:10,1']);
Route::get('mobile/claims/{claim}', [MobileClaimController::class, 'show']);
Route::get('mobile/claims/{claim}/timeline', [MobileClaimController::class, 'timeline']);
Route::get('mobile/claims/{claim}/evidence', [MobileClaimEvidenceController::class, 'index']);
Route::post('mobile/claims/{claim}/evidence', [MobileClaimEvidenceController::class, 'store'])->middleware('throttle:20,1');
Route::get('mobile/claims/{claim}/parties', [MobileClaimPartyController::class, 'index']);
Route::post('mobile/claims/{claim}/parties', [MobileClaimPartyController::class, 'store'])->middleware('throttle:20,1');
