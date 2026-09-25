<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Approvals\ApprovalService;
use App\Application\Configuration\ConfigurationGovernanceService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** REQ-RBAC-005/006, REQ-SET-005 — the one approval engine, its domain handlers and routes/approvals.php. */
final class ApprovalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Built-in handlers live in ApprovalService::DEFAULT_HANDLERS; singleton so registerHandler() sticks.
        $this->app->singleton(ApprovalService::class);
        $this->app->singleton(ConfigurationGovernanceService::class);
    }

    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/approvals.php'));
        }
    }
}
