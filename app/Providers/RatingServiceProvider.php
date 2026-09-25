<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Rating\Console\TariffsAdvanceCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** REQ-RAT-001..005 — rating v2 wiring: routes/rating.php and the daily tariffs:advance sweep. */
final class RatingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([TariffsAdvanceCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('tariffs:advance')->dailyAt('00:05')->withoutOverlapping()->onOneServer();
        });
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/rating.php'));
        }
    }
}
