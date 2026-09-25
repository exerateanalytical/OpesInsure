<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\Vehicles\VehicleMasterController;
use App\Interfaces\Http\Middleware\DeprecatedRouteAlias;
use Illuminate\Support\Facades\Route;

// Vehicle master data (App\Providers\VehicleMasterServiceProvider).
Route::prefix('v1')->group(function (): void {
    Route::middleware('throttle:120,1')->group(function (): void {
        Route::get('public/vehicles/makes', [VehicleMasterController::class, 'makes']);
        Route::get('public/vehicles/makes/{code}/models', [VehicleMasterController::class, 'models']);
        Route::get('public/vehicles/reference', [VehicleMasterController::class, 'reference']);
        // CUST-007: generations / variants (empty until admins or imports add them).
        Route::get('public/vehicles/models/{model}/generations', [VehicleMasterController::class, 'generations']);
        Route::get('public/vehicles/models/{model}/generations/{generation}/years', [VehicleMasterController::class, 'years']);
        Route::get('public/vehicles/models/{model}/generations/{generation}/variants', [VehicleMasterController::class, 'variants']);
        Route::get('public/vehicles/config', [VehicleMasterController::class, 'config']);
    });

    Route::middleware(['auth:api', 'json.api', 'throttle:20,1'])->group(function (): void {
        // REQ-DUP-013: canonical is master-data/suggestions with domain "vehicle"
        // (VehicleSuggestionIntake). Kept for installed app builds; deprecated.
        Route::post('mobile/vehicles/master-review', [VehicleMasterController::class, 'submitReview'])
            ->middleware(DeprecatedRouteAlias::using('master-data/suggestions', 'REQ-DUP-013'));
    });
});
