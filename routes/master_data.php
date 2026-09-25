<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\MasterData\MasterDataController;
use App\Interfaces\Http\Controllers\Api\V1\MasterData\MasterDataOwnershipController;
use Illuminate\Support\Facades\Route;

/*
 | Institutional master data (App\Providers\MasterDataServiceProvider).
 | Reads are public, cached and versioned (catalog_version per domain) so the
 | app can cache catalogues offline. Suggestions need a signed-in user.
 */
Route::prefix('v1')->group(function (): void {
    // REQ-MDM-006 / REQ-MDM-007: tenant overrides, private values, carrier/broker mappings, duplicates and
    // maker-checker merges. Registered before the public {domain} routes so these literal paths win.
    Route::middleware(['auth:api', 'tenant', 'json.api', 'throttle:120,1'])->group(function (): void {
        Route::get('master-data/overrides', [MasterDataOwnershipController::class, 'overrides'])->middleware('permission:master_data.overrides.manage');
        Route::post('master-data/overrides', [MasterDataOwnershipController::class, 'setOverride'])->middleware('permission:master_data.overrides.manage');
        Route::delete('master-data/overrides/{override}', [MasterDataOwnershipController::class, 'removeOverride'])->middleware('permission:master_data.overrides.manage')->whereUuid('override');
        Route::post('master-data/{domain}/{list}/private-values', [MasterDataOwnershipController::class, 'addPrivateValue'])->middleware('permission:master_data.overrides.manage')
            ->where(['domain' => '[a-z0-9_]+', 'list' => '[a-z0-9_]+']);
        Route::get('master-data/carrier-mappings', [MasterDataOwnershipController::class, 'carrierMappings'])->middleware('permission:master_data.mappings.manage');
        Route::put('master-data/carrier-mappings', [MasterDataOwnershipController::class, 'putCarrierMapping'])->middleware('permission:master_data.mappings.manage');
        Route::get('master-data/broker-mappings', [MasterDataOwnershipController::class, 'brokerMappings'])->middleware('permission:master_data.mappings.manage');
        Route::put('master-data/broker-mappings', [MasterDataOwnershipController::class, 'putBrokerMapping'])->middleware('permission:master_data.mappings.manage');
        // Owner Workflow Data Master v1: reference catalogue statuses (PENDING_SOURCE lists visible as empty and pending).
        Route::get('master-data/workflow-statuses', [\App\Application\MasterData\Http\WorkflowDataStatusController::class, 'index'])->middleware('permission:master_data.workflow_status.view');
        Route::get('master-data/duplicates', [MasterDataOwnershipController::class, 'duplicates'])->middleware('permission:master_data.merge.request');
        Route::post('master-data/merges', [MasterDataOwnershipController::class, 'requestMerge'])->middleware('permission:master_data.merge.request');
        Route::post('master-data/merges/{merge}/decision', [MasterDataOwnershipController::class, 'decideMerge'])->middleware('permission:master_data.merge.approve')->whereUuid('merge');
    });

    Route::middleware('throttle:240,1')->group(function (): void {
        Route::get('master-data/versions', [MasterDataController::class, 'versions']);
        Route::get('master-data', [MasterDataController::class, 'index']);
        Route::get('master-data/{domain}', [MasterDataController::class, 'domain'])->where('domain', '[a-z0-9_]+');
        Route::get('master-data/{domain}/search', [MasterDataController::class, 'search'])->where('domain', '[a-z0-9_]+');
        Route::get('master-data/{domain}/{id}', [MasterDataController::class, 'show'])->where('domain', '[a-z0-9_]+');
        // Aliases kept from the first brief.
        Route::get('public/master-data/{domain}', [MasterDataController::class, 'domain'])->where('domain', '[a-z0-9_]+')->middleware(\App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('master-data/{domain}', 'REQ-DUP-013'));
        Route::get('public/master-data/{domain}/{id}', [MasterDataController::class, 'show'])->where('domain', '[a-z0-9_]+')->middleware(\App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('master-data/{domain}/{id}', 'REQ-DUP-013'));
    });

    Route::middleware(['auth:api', 'json.api', 'throttle:30,1'])->group(function (): void {
        Route::post('master-data/suggestions', [MasterDataController::class, 'suggest']);
        // REQ-DUP-013: canonical is master-data/suggestions.
        Route::post('mobile/master-data/review', [MasterDataController::class, 'suggest'])->middleware(\App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('master-data/suggestions', 'REQ-DUP-013'));
    });
});
