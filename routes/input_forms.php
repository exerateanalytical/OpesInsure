<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\Forms\MobileFormSchemaController;
use Illuminate\Support\Facades\Route;

/*
 | Selection-first form schemas (App\Providers\InputFormsServiceProvider).
 | Reference data only: public, throttled like master-data.
 */
Route::prefix('v1')->middleware('throttle:240,1')->group(function (): void {
    Route::get('forms', [MobileFormSchemaController::class, 'index']);
    Route::get('forms/{form}', [MobileFormSchemaController::class, 'show'])->where('form', '[a-z_]+');
});
