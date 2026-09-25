<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Risks\RiskAssetVersionRecorder;
use App\Models\RiskAsset;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * REQ-RSK-001 / REQ-SRC-001 — routes/risks_search.php and the
 * risk_asset_versions snapshot hook (every RiskAsset write).
 */
final class RiskSearchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/risks_search.php'));
        }

        RiskAsset::saved(function (RiskAsset $asset): void {
            $this->app->make(RiskAssetVersionRecorder::class)->record($asset);
        });
    }
}
