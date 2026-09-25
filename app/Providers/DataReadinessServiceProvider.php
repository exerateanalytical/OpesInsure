<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\DataReadiness\WorkflowDataMaster;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Workflow Institutional Data Master v1: data-status vocabulary, production-use guard, readiness registry (routes/data_readiness.php). */
final class DataReadinessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkflowDataMaster::class);
        $this->app->bind(DataReadinessRegistry::class);
    }

    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/data_readiness.php'));
        }
    }
}
