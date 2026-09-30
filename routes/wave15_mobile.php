<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\Directory\PublicInstitutionController;
use App\Interfaces\Http\Middleware\HideDemoInstitutions;
use Illuminate\Support\Facades\Route;

/*
 | Wave 15 — mobile audit remediation (Phase 4). Included from routes/api.php
 | inside the auth:api + tenant + json.api group. The institution directory
 | is public (browsed before sign-in), so it opts out of auth and tenant.
 */

/*
 | Per-IP limit sized for carrier-grade NAT: many phones on one mobile
 | network share a public IP, and the app caches the directory for 10 min.
 */
Route::withoutMiddleware(['auth:api', 'tenant'])->group(function (): void {
    Route::get('public/institutions', [PublicInstitutionController::class, 'index'])->middleware(['throttle:300,1', HideDemoInstitutions::class]);
    Route::get('public/institutions/{institution}', [PublicInstitutionController::class, 'show'])->middleware(['throttle:300,1', HideDemoInstitutions::class]);
    Route::get('public/insurance-classes', [PublicInstitutionController::class, 'classes'])->middleware('throttle:300,1');
});
