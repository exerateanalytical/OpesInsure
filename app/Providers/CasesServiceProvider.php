<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Cases\CaseMachine;
use App\Application\Cases\Console\SlaTickCommand;
use App\Application\Cases\Sla\BusinessHoursCalendar;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * REQ-CAS-001 / REQ-CAL-001 — Case engine wiring: routes/cases.php, the
 * cases:sla-tick command (every minute) and scoped calendar cache.
 * Register in bootstrap/providers.php after StateMachineServiceProvider and TemporalServiceProvider.
 */
final class CasesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(BusinessHoursCalendar::class);
        $this->app->singleton(CaseMachine::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SlaTickCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('cases:sla-tick')->everyMinute()->withoutOverlapping()->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/cases.php'));
        }
    }
}
