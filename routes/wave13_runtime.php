<?php

use App\Interfaces\Http\Controllers\Api\V1\Security\MobileStepUpController;
use Illuminate\Support\Facades\Route;

// Step-up authentication (CLAUDE_MERGE_GUIDE.md, Patch 7): elevates an
// already-authenticated mobile session for one sensitive purpose at a time.
// Lives inside the ['auth:api','tenant','json.api'] group it is required
// from (see routes/api.php) — step-up assumes a real session already
// exists. See App\Application\Security\MobileStepUpService and
// App\Interfaces\Http\Middleware\RequireStepUpGrant (alias 'step-up'),
// which actually enforces the grant on the protected endpoint.
Route::post('mobile/security/step-up/request', [MobileStepUpController::class, 'request'])
    ->middleware(['throttle:10,1', 'idempotency:mobile.step_up.request']);
Route::post('mobile/security/step-up/verify', [MobileStepUpController::class, 'verify'])
    ->middleware(['throttle:10,1', 'idempotency:mobile.step_up.verify']);
