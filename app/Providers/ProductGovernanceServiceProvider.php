<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\PublishScheduledProductVersions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Batch 6A — product governance + sandbox wiring (REQ-PRD-007…010): routes/product_governance.php, scheduled publication. */
final class ProductGovernanceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/product_governance.php'));
        }
        if ($this->app->runningInConsole()) {
            $this->commands([PublishScheduledProductVersions::class]);
            $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->command('catalogue:publish-scheduled')->everyFiveMinutes()->withoutOverlapping());
        }
    }
}
