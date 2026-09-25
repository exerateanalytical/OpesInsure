<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\MasterData\MasterDataController;
use Illuminate\Support\Facades\Route;

/*
 | Institutional master data (App\Providers\MasterDataServiceProvider).
 | Reads are public, cached and versioned (catalog_version per domain) so the
 | app can cache catalogues offline. Suggestions need a signed-in user.
 */
Route::prefix('v1')->group(function (): void {
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
