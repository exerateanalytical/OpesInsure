<?php

declare(strict_types=1);

use App\Application\Rating\Http\RatingController;
use Illuminate\Support\Facades\Route;

/*
 | REQ-RAT-001..005 — rating v2. Loaded by App\Providers\RatingServiceProvider.
 | Tariff create/submit/approve stay on routes/api.php (TariffController); quotes/{quote}/rate stays the
 | canonical rating call. POST v1/insurance/rate is ONLY a stateless preview of it (traceability §3.1).
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('tariffs/{tariff}', [RatingController::class, 'tariff'])->whereUuid('tariff')->middleware('permission:tariff.manage');
    Route::post('tariffs/{tariff}/reject', [RatingController::class, 'reject'])->whereUuid('tariff')->middleware('permission:tariff.approve');
    foreach (['schedule', 'activate', 'expire'] as $event) {
        Route::post("tariffs/{tariff}/{$event}", [RatingController::class, $event])->whereUuid('tariff')->middleware('permission:tariff.publish');
    }

    Route::prefix('rating')->group(function (): void {
        Route::get('charge-codes', [RatingController::class, 'chargeCodes'])->middleware('permission:rating.charges.view');
        Route::get('charge-tables/{kind}', [RatingController::class, 'chargeTables'])->whereIn('kind', ['tax', 'fee'])->middleware('permission:rating.charges.view');
        Route::post('charge-tables/{kind}', [RatingController::class, 'storeChargeTable'])->whereIn('kind', ['tax', 'fee'])->middleware('permission:rating.charges.manage');
        Route::post('charge-tables/{kind}/{id}/approve', [RatingController::class, 'approveChargeTable'])->whereIn('kind', ['tax', 'fee'])->whereUuid('id')->middleware('permission:rating.charges.approve');
        // Owner decision 10 — explicit maker-checker rate verification (DEMO/UNVERIFIED → OWNER_CONFIRMED).
        Route::post('charge-tables/{kind}/{id}/verification', [RatingController::class, 'requestChargeVerification'])->whereIn('kind', ['tax', 'fee'])->whereUuid('id')->middleware('permission:rating.charges.manage');
        Route::post('charge-tables/{kind}/{id}/verification/decide', [RatingController::class, 'decideChargeVerification'])->whereIn('kind', ['tax', 'fee'])->whereUuid('id')->middleware('permission:rating.charges.verify');
        Route::get('runs/{run}', [RatingController::class, 'run'])->whereUuid('run')->middleware('permission:rating.runs.view');
        Route::post('runs/{run}/reproduce', [RatingController::class, 'reproduce'])->whereUuid('run')->middleware('permission:rating.runs.view');
    });

    Route::post('insurance/rate', [RatingController::class, 'preview'])->middleware(['permission:quotes.rate', 'throttle:30,1']);
});
