<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Vehicles\RiskAssetVehicleSync;
use App\Models\RiskAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Vehicle master data: API routes (routes/vehicles.php), the deploy hook
 * (`optimize` → opesinsure:seed-vehicles) and the risk-asset observer that
 * keeps risk_asset_vehicles in step with VEHICLE asset facts.
 */
final class VehicleMasterServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->optimizes(optimize: 'opesinsure:seed-vehicles', key: 'vehicle-master-data');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/vehicles.php'));
        }

        RiskAsset::saved(function (RiskAsset $asset): void {
            try {
                // Savepoint: a failure here must never abort the asset write.
                DB::transaction(fn () => $this->app->make(RiskAssetVehicleSync::class)->sync($asset));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
