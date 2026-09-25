<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Quotes\Console\ExpireQuotesCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Batch 6B — REQ-QUO-001…005, REQ-DST-003: routes/quotes.php and the quotes:expire sweep (config/quotes.php). */
final class QuoteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireQuotesCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('quotes:expire')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
        });
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/quotes.php'));
        }
    }
}
