<?php

declare(strict_types=1);

use App\Application\Reinsurance\Directory\Http\ReinsuranceDirectoryController;
use Illuminate\Support\Facades\Route;

/*
 | Gap Closure Pack v1 (07) — reinsurer / reinsurance-broker directory and approved-security gate.
 | Loaded by App\Application\Reinsurance\Directory\ReinsuranceDirectoryServiceProvider.
 */
Route::prefix('v1/reinsurance')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('directory', [ReinsuranceDirectoryController::class, 'directory'])->middleware('permission:reinsurance.treaties.view');
    Route::get('reference', [ReinsuranceDirectoryController::class, 'reference'])->middleware('permission:reinsurance.treaties.view');
    Route::post('reinsurers/{reinsurer}/security', [ReinsuranceDirectoryController::class, 'security'])
        ->middleware('permission:reinsurance.reinsurers.approve_security')->whereUuid('reinsurer');
});
