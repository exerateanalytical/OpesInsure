<?php

declare(strict_types=1);

use App\Application\Catalogue\Governance\Http\ProductGovernanceController as G;
use Illuminate\Support\Facades\Route;

/*
 | Batch 6A — product governance, completeness and sandbox (REQ-PRD-007…010).
 | Loaded by App\Providers\ProductGovernanceServiceProvider under the `api` group.
 | advance / reject check the stage-specific permission in the controller (catalogue.manage → catalogue.review → catalogue.publish).
 */
Route::prefix('v1/catalogue')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('versions/{version}/governance', [G::class, 'show'])->middleware('permission:catalogue.view');
    Route::patch('versions/{version}/governance', [G::class, 'update'])->middleware('permission:catalogue.manage');
    Route::post('versions/{version}/governance/advance', [G::class, 'advance'])->middleware('permission:catalogue.view');
    Route::post('versions/{version}/governance/reject', [G::class, 'reject'])->middleware('permission:catalogue.view');
    Route::post('versions/{version}/governance/publish', [G::class, 'publish'])->middleware('permission:catalogue.publish');
    Route::get('versions/{version}/completeness', [G::class, 'completeness'])->middleware('permission:catalogue.view');
    Route::get('versions/{version}/diff', [G::class, 'diff'])->middleware('permission:catalogue.view');

    Route::get('versions/{version}/test-cases', [G::class, 'testCases'])->middleware('permission:catalogue.view');
    Route::post('versions/{version}/test-cases', [G::class, 'storeTestCase'])->middleware('permission:catalogue.test');
    Route::delete('test-cases/{case}', [G::class, 'destroyTestCase'])->middleware('permission:catalogue.test');
    Route::post('versions/{version}/sandbox', [G::class, 'sandbox'])->middleware('permission:catalogue.test');
    Route::get('versions/{version}/test-runs', [G::class, 'testRuns'])->middleware('permission:catalogue.view');
    Route::post('versions/{version}/test-runs', [G::class, 'runTests'])->middleware('permission:catalogue.test');
});
