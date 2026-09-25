<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\Import\ImportBatchController;
use Illuminate\Support\Facades\Route;

/*
 | REQ-IMP-001 generic import pipeline (App\Providers\ImportServiceProvider).
 | upload → map → validate → duplicates → preview → submit → approve (maker-checker) → import → audit.
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api', 'throttle:60,1'])->group(function (): void {
    Route::get('imports/targets', [ImportBatchController::class, 'targets'])->middleware('permission:imports.create');
    Route::get('imports', [ImportBatchController::class, 'index'])->middleware('permission:imports.create');
    Route::post('imports', [ImportBatchController::class, 'store'])->middleware('permission:imports.create');
    Route::get('imports/{batch}', [ImportBatchController::class, 'show'])->middleware('permission:imports.create')->whereUuid('batch');
    Route::put('imports/{batch}/mapping', [ImportBatchController::class, 'map'])->middleware('permission:imports.create')->whereUuid('batch');
    Route::post('imports/{batch}/submit', [ImportBatchController::class, 'submit'])->middleware('permission:imports.create')->whereUuid('batch');
    Route::post('imports/{batch}/cancel', [ImportBatchController::class, 'cancel'])->middleware('permission:imports.create')->whereUuid('batch');
    Route::post('imports/{batch}/approve', [ImportBatchController::class, 'approve'])->middleware('permission:imports.approve')->whereUuid('batch');
    Route::post('imports/{batch}/reject', [ImportBatchController::class, 'reject'])->middleware('permission:imports.approve')->whereUuid('batch');
});
