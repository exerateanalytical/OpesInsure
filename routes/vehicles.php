<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\Vehicles\VehicleMasterController;
use Illuminate\Support\Facades\Route;

// Vehicle master data (App\Providers\VehicleMasterServiceProvider).
Route::prefix('v1')->group(function (): void {
    Route::middleware('throttle:120,1')->group(function (): void {
        Route::get('public/vehicles/makes', [VehicleMasterController::class, 'makes']);
        Route::get('public/vehicles/makes/{code}/models', [VehicleMasterController::class, 'models']);
        Route::get('public/vehicles/reference', [VehicleMasterController::class, 'reference']);
    });

    Route::middleware(['auth:api', 'json.api', 'throttle:20,1'])->group(function (): void {
        Route::post('mobile/vehicles/master-review', [VehicleMasterController::class, 'submitReview']);
    });
});
