<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Owner decisions 2026-09-25 — routes/owner_decisions.php: authority types (#12, REQ-AUTH-001),
 * premium-to-cover rules (#17, REQ-POL-008), versioned public-holiday / hazard-zone datasets.
 * Case aggregate, SLA overrides and calendar breaks live on the case engine (routes/cases.php).
 */
final class OwnerDecisionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/owner_decisions.php'));
        }
    }
}
