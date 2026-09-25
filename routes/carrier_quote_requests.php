<?php

declare(strict_types=1);

use App\Application\CarrierOperations\QuoteRequests\Http\QuoteRequestController as C;
use Illuminate\Support\Facades\Route;

/*
 | REQ-QUO-006 manual quotation (AOM Mode 1). Loaded by App\Providers\CarrierQuoteRequestServiceProvider under `api`.
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    // Distributor (broker / platform) side.
    Route::get('quotes/{quote}/carrier-requests', [C::class, 'forQuote'])->whereUuid('quote')->middleware('permission:quotes.carrier_requests.view');
    Route::post('quotes/{quote}/carrier-requests', [C::class, 'store'])->whereUuid('quote')->middleware('permission:quotes.carrier_requests.create');
    Route::post('quotes/{quote}/carrier-requests/{request}/cancel', [C::class, 'cancel'])->whereUuid(['quote', 'request'])->middleware('permission:quotes.carrier_requests.create');
    Route::middleware('permission:quotes.carrier_requests.record_on_behalf')->group(function (): void {
        Route::post('quotes/{quote}/carrier-requests/{request}/offer-on-behalf', [C::class, 'offerOnBehalf'])->whereUuid(['quote', 'request']);
        Route::post('quotes/{quote}/carrier-requests/{request}/decline-on-behalf', [C::class, 'declineOnBehalf'])->whereUuid(['quote', 'request']);
    });

    // Insurer work queue (portal + API), scoped to the caller's carrier.
    Route::middleware('permission:carrier.quote_requests.view')->group(function (): void {
        Route::get('carrier/quote-requests', [C::class, 'index']);
        Route::get('carrier/quote-requests/{request}', [C::class, 'show'])->whereUuid('request');
    });
    Route::middleware('permission:carrier.quote_requests.respond')->group(function (): void {
        Route::post('carrier/quote-requests/{request}/start', [C::class, 'start'])->whereUuid('request');
        Route::post('carrier/quote-requests/{request}/offer', [C::class, 'offer'])->whereUuid('request');
        Route::post('carrier/quote-requests/{request}/decline', [C::class, 'decline'])->whereUuid('request');
    });
});
