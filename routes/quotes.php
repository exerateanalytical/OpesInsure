<?php

declare(strict_types=1);

use App\Application\Quotes\Http\QuoteWorkflowController as C;
use Illuminate\Support\Facades\Route;

/*
 | Batch 6B — quote workflow (REQ-QUO-001…005, REQ-DST-003). Loaded by App\Providers\QuoteServiceProvider.
 | POST quotes, GET quotes/{q}, POST quotes/{q}/rate, POST quotes/{q}/offers/{o}/accept stay on routes/api.php;
 | mobile/quotes* stay on routes/api.php (MobileQuoteController, thin adapter over QuoteService).
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::patch('quotes/{quote}', [C::class, 'amend'])->whereUuid('quote')->middleware('throttle:60,1');
    Route::post('quotes/{quote}/generate', [C::class, 'generate'])->whereUuid('quote');
    Route::post('quotes/{quote}/send', [C::class, 'send'])->whereUuid('quote')->middleware('throttle:30,1');
    Route::post('quotes/{quote}/decline', [C::class, 'decline'])->whereUuid('quote');
    Route::post('quotes/{quote}/cancel', [C::class, 'cancel'])->whereUuid('quote');
    Route::get('quotes/{quote}/history', [C::class, 'history'])->whereUuid('quote');
    Route::get('quotes/{quote}/document', [C::class, 'document'])->whereUuid('quote');
    Route::post('quotes/{quote}/offers/{offer}/premium-overrides', [C::class, 'requestOverride'])->whereUuid(['quote', 'offer']);
    Route::post('quotes/{quote}/offers/{offer}/premium-overrides/{override}/decision', [C::class, 'decideOverride'])->whereUuid(['quote', 'offer', 'override']);
    Route::post('quotes/{quote}/offers/{offer}/premium-overrides/{override}/apply', [C::class, 'applyOverride'])->whereUuid(['quote', 'offer', 'override']);

    Route::get('quote-comparisons', [C::class, 'comparisons']);
    Route::post('quote-comparisons', [C::class, 'storeComparison'])->middleware('throttle:60,1');
    Route::get('quote-comparisons/{comparison}', [C::class, 'showComparison'])->whereUuid('comparison');
});

// WF-012 share link: no login; unguessable 48-char token (only its sha256 is stored); expires with the quote.
Route::prefix('v1')->middleware(['throttle:30,1'])->group(function (): void {
    Route::get('quote-shares/{token}', [C::class, 'openShare'])->where('token', '[A-Za-z0-9]{48}');
});
