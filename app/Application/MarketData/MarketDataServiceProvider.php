<?php

declare(strict_types=1);

namespace App\Application\MarketData;

use App\Application\Import\ImportTargetRegistry;
use App\Application\MarketData\Import\BrokerDirectoryEnrichmentTarget;
use App\Application\MarketData\Import\CimaAuthorizationImportTarget;
use App\Application\MarketData\Import\CommissionTableImportTarget;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Gap closure 01 (Insurance Market, Product, Agreement & Commission Master): import targets on the generic
 * ImportPipeline, Data Readiness gates and routes/market_data.php.
 */
final class MarketDataServiceProvider extends ServiceProvider
{
    public const TARGETS = [
        CimaAuthorizationImportTarget::KEY => CimaAuthorizationImportTarget::class,
        BrokerDirectoryEnrichmentTarget::KEY => BrokerDirectoryEnrichmentTarget::class,
        CommissionTableImportTarget::KEY => CommissionTableImportTarget::class,
    ];

    public function register(): void
    {
        $this->app->afterResolving(ImportTargetRegistry::class, function (ImportTargetRegistry $registry): void {
            foreach (self::TARGETS as $key => $class) {
                $registry->register($key, $class);
            }
        });
    }

    public function boot(): void
    {
        if ($this->app->resolved(ImportTargetRegistry::class)) {
            foreach (self::TARGETS as $key => $class) {
                $this->app->make(ImportTargetRegistry::class)->register($key, $class);
            }
        }
        MarketDataReadiness::register();
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/market_data.php'));
        }
    }
}
