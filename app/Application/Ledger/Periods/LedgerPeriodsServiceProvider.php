<?php

declare(strict_types=1);

namespace App\Application\Ledger\Periods;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Q10 2026-09-29 — FIN-023: HTTP routes of the accounting period close (routes/ledger_periods.php). */
final class LedgerPeriodsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/ledger_periods.php'));
        }
    }
}
