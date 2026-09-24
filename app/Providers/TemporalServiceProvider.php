<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Temporal\BusinessCalendar;
use App\Application\Temporal\ReferenceDateResolver;
use App\Application\Temporal\VersionResolver;
use App\Domain\Shared\Clock\Clock;
use App\Domain\Shared\Clock\SystemClock;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** REQ-TMP-001 / REQ-TMP-002 — Temporal engine (ICE E1): Clock binding + diagnostic routes. */
final class TemporalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(Clock::class, fn () => new SystemClock((string) config('app.timezone', 'Africa/Douala')));
        $this->app->scoped(ReferenceDateResolver::class);
        $this->app->scoped(VersionResolver::class);
        $this->app->scoped(BusinessCalendar::class);
    }

    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/temporal.php'));
        }
    }
}
