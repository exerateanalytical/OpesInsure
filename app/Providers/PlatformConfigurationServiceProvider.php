<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Configuration\ConfigurationGovernanceService;
use App\Application\Configuration\ConfigurationInheritance;
use App\Application\Configuration\PlatformSetupService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Batch 3 / 3E — REQ-SET-001, REQ-SET-004, REQ-SEED-001/004/005, REQ-DUP-023.
 * Registers the governance appliers for `platform.settings` and `configuration.override` on the existing
 * ConfigurationGovernanceService (no second governance path) and loads routes/platform_configuration.php.
 */
final class PlatformConfigurationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ApprovalServiceProvider binds it as a singleton; singletonIf keeps that binding when it is already there.
        $this->app->singletonIf(ConfigurationGovernanceService::class);
        $this->app->afterResolving(ConfigurationGovernanceService::class, function (ConfigurationGovernanceService $governance, $app): void {
            $governance->registerApplier(PlatformSetupService::CONFIG_TYPE, fn ($cs) => $app->make(PlatformSetupService::class)->apply($cs));
            $governance->registerApplier(ConfigurationInheritance::CONFIG_TYPE, fn ($cs) => $app->make(ConfigurationInheritance::class)->apply($cs));
        });
    }

    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/platform_configuration.php'));
        }
    }
}
