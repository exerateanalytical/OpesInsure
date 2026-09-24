<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\Regulatory\RegulatoryDictionaryController;
use Illuminate\Support\Facades\Route;

/*
 | CIMA Regulatory Dictionary — public, cached, read-only reference data.
 | Loaded by App\Providers\RegulatoryServiceProvider under the `api` group.
 | Customer apps show `label`, never the raw codes.
 */
Route::prefix('v1/public/regulatory')->middleware('throttle:120,1')->group(function (): void {
    Route::get('terms', [RegulatoryDictionaryController::class, 'terms']);
    Route::get('cima/branches', [RegulatoryDictionaryController::class, 'branches']);
    Route::get('cima/micro-branches', [RegulatoryDictionaryController::class, 'microBranches']);
    Route::get('cima/reporting-categories', [RegulatoryDictionaryController::class, 'reportingCategories']);
});
