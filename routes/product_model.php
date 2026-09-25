<?php

declare(strict_types=1);

use App\Application\Catalogue\Http\ProductModelController as C;
use Illuminate\Support\Facades\Route;

/*
 | Batch 5A — product model (REQ-PRD-001…006).
 | Loaded by App\Providers\ProductModelServiceProvider under the `api` group.
 */
Route::prefix('v1/catalogue')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('families', [C::class, 'families'])->middleware('permission:catalogue.view');
    Route::post('families', [C::class, 'storeFamily'])->middleware('permission:catalogue.manage');

    Route::get('carrier-products', [C::class, 'carrierProducts'])->middleware('permission:catalogue.view');
    Route::post('carrier-products', [C::class, 'storeCarrierProduct'])->middleware('permission:catalogue.manage');
    Route::get('carrier-products/{product}', [C::class, 'showCarrierProduct'])->middleware('permission:catalogue.view');
    Route::patch('carrier-products/{product}', [C::class, 'updateCarrierProduct'])->middleware('permission:catalogue.manage');
    Route::post('carrier-products/{product}/versions', [C::class, 'storeVersion'])->middleware('permission:catalogue.manage');

    Route::get('versions/resolve', [C::class, 'resolve'])->middleware('permission:catalogue.view');
    Route::get('versions/{version}/hierarchy', [C::class, 'hierarchy'])->middleware('permission:catalogue.view');
    Route::get('versions/{version}/snapshot', [C::class, 'snapshot'])->middleware('permission:catalogue.view');
    Route::post('versions/{version}/{action}', [C::class, 'transition'])->whereIn('action', ['approve', 'suspend', 'reinstate', 'retire'])->middleware('permission:catalogue.publish');

    Route::post('versions/{version}/plans', [C::class, 'storePlan'])->middleware('permission:catalogue.manage');
    Route::put('plans/{plan}/coverages', [C::class, 'syncPlanCoverages'])->middleware('permission:catalogue.manage');
    Route::put('versions/{version}/coverages/{coverage}', [C::class, 'configureCoverage'])->middleware('permission:catalogue.manage');
    Route::post('versions/{version}/limits', [C::class, 'storeLimit'])->middleware('permission:catalogue.manage');
    Route::post('versions/{version}/deductibles', [C::class, 'storeDeductible'])->middleware('permission:catalogue.manage');
    Route::delete('{kind}/{id}', [C::class, 'destroyTerm'])->whereIn('kind', ['limits', 'deductibles'])->middleware('permission:catalogue.manage');
    Route::post('versions/{version}/indemnity-preview', [C::class, 'indemnity'])->middleware('permission:catalogue.view');

    Route::post('versions/{version}/exclusions', [C::class, 'attachExclusion'])->middleware('permission:catalogue.manage');
    Route::get('exclusions/{exclusion}/legal-texts', [C::class, 'legalTextAt'])->middleware('permission:catalogue.view');
    Route::post('exclusions/{exclusion}/legal-texts', [C::class, 'draftLegalText'])->middleware('permission:catalogue.manage');
    Route::post('legal-texts/{text}/approve', [C::class, 'approveLegalText'])->middleware('permission:catalogue.publish');
});
