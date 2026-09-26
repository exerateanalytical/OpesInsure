<?php

declare(strict_types=1);

use App\Application\MarketData\Http\MarketDataController;
use Illuminate\Support\Facades\Route;

/** Gap closure 01 — insurance market, product, agreement & commission gates (MarketDataServiceProvider). */
Route::prefix('v1/market-data')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('readiness', [MarketDataController::class, 'readiness'])->middleware('permission:data_readiness.view');
    Route::post('carrier-broker-agreements/{agreement}/verify-source', [MarketDataController::class, 'verifyAgreementSource'])
        ->whereUuid('agreement')->middleware('permission:distribution.agreements.approve');
});
