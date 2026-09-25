<?php

declare(strict_types=1);

use App\Application\Distribution\Http\DistributionController;
use Illuminate\Support\Facades\Route;

/*
 | REQ-DST-001 / REQ-DST-002 sellable catalogue + sellability, REQ-AOM-002 execution adapters.
 | Loaded by App\Providers\DistributionServiceProvider under the `api` group.
 */
Route::prefix('v1/distribution')->middleware(['auth:api', 'tenant', 'json.api', 'permission:distribution.catalogue.view'])->group(function (): void {
    Route::get('catalogue', [DistributionController::class, 'catalogue']);
    Route::get('sellability', [DistributionController::class, 'sellability']);
    Route::get('execution-plan', [DistributionController::class, 'executionPlan']);
});
