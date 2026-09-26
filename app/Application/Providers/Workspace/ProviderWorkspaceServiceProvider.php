<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace;

use App\Application\Import\ImportTargetRegistry;
use App\Application\Providers\Workspace\Filament\ProviderPanelProvider;
use App\Application\Providers\Workspace\Import\MedicalServiceImportTarget;
use App\Application\Providers\Workspace\Import\ProviderMasterImportTarget;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Provider Portal Gap-Free spec v1 + gap closure pack 04: provider-side API (routes/provider_workspace.php), the
 * Filament provider panel (/provider), the import targets (provider master, medical services) and the Data Readiness
 * gates. One line in bootstrap/providers.php.
 */
final class ProviderWorkspaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(ProviderPanelProvider::class);
        $this->callAfterResolving(ImportTargetRegistry::class, function (ImportTargetRegistry $r): void {
            $r->register('health_provider_master', ProviderMasterImportTarget::class);
            $r->register('medical_services', MedicalServiceImportTarget::class);
        });
    }

    public function boot(): void
    {
        ProviderDataGates::register();
        if (! $this->app->routesAreCached()) {
            Route::group([], base_path('routes/provider_workspace.php'));
        }
    }
}
