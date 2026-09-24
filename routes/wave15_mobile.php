<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\Directory\PublicInstitutionController;
use Illuminate\Support\Facades\Route;

/*
 | Wave 15 — mobile audit remediation (Phase 4). Included from routes/api.php
 | inside the auth:api + tenant + json.api group. The institution directory
 | is public (browsed before sign-in), so it opts out of auth and tenant.
 */

Route::withoutMiddleware(['auth:api', 'tenant'])->group(function (): void {
    Route::get('public/institutions', [PublicInstitutionController::class, 'index'])->middleware('throttle:60,1');
    Route::get('public/institutions/{institution}', [PublicInstitutionController::class, 'show'])->middleware('throttle:60,1');
    Route::get('public/insurance-classes', [PublicInstitutionController::class, 'classes'])->middleware('throttle:60,1');
});
