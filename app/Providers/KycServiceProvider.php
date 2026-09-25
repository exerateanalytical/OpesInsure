<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Kyc\Console\KycExpireCommand;
use App\Application\Kyc\Console\KycRescreenDueCommand;
use App\Application\Kyc\Screening\ScreeningAdapter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** REQ-KYC-001..003 — KYC wiring: routes/kyc.php, screening adapter binding, daily kyc:expire. */
final class KycServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ScreeningAdapter::class, fn ($app) => $app->make(config('kyc.screening.adapter')));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([KycExpireCommand::class, KycRescreenDueCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('kyc:expire')->dailyAt('02:15')->withoutOverlapping()->onOneServer();
            $schedule->command('kyc:rescreen-due')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
        });
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/kyc.php'));
        }
    }
}
