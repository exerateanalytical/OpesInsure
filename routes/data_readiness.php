<?php

declare(strict_types=1);

use App\Application\DataReadiness\Http\DataReadinessController;
use Illuminate\Support\Facades\Route;

/*
 | Workflow Institutional Data Master v1 — Data Readiness registry. Loaded by App\Providers\DataReadinessServiceProvider.
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api', 'permission:data_readiness.view'])->group(function (): void {
    Route::get('data-readiness', [DataReadinessController::class, 'index']);
    Route::get('data-readiness/vocabulary', [DataReadinessController::class, 'vocabulary']);
});
