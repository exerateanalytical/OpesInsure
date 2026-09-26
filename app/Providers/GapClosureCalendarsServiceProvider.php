<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Gap Closure Pack v1 file 02 (geography / calendars / occupations / industries / legal entities): routes/gap_closure_calendars.php. */
final class GapClosureCalendarsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/gap_closure_calendars.php'));
        }
    }
}
