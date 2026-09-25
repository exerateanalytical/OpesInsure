<?php

declare(strict_types=1);

use App\Application\Risks\Http\RiskAssetVersionController;
use App\Application\Search\Http\SearchController;
use Illuminate\Support\Facades\Route;

/*
 | REQ-RSK-001 (insured-object types + version history) and REQ-SRC-001
 | (global search). Loaded by App\Providers\RiskSearchServiceProvider under
 | the `api` group. Asset CRUD stays on the canonical /v1/risk-assets
 | (RiskAssetController) and /v1/mobile/assets routes.
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('search', SearchController::class)->middleware('throttle:60,1');

    Route::get('risk-asset-types', [RiskAssetVersionController::class, 'types']);
    Route::get('risk-assets/{riskAsset}/versions', [RiskAssetVersionController::class, 'index'])->whereUuid('riskAsset');
    Route::get('risk-assets/{riskAsset}/versions/{version}', [RiskAssetVersionController::class, 'show'])->whereUuid('riskAsset')->whereNumber('version');
});
