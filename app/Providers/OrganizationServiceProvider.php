<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Settings\FeatureFlags;
use App\Application\Settings\TimezoneCatalogue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Batch 2 / 2D — REQ-TEN-001/002, REQ-ORG-001, REQ-SEC-004, REQ-TMP-003 (TimezoneResolver is bound in TemporalServiceProvider). */
final class OrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TimezoneCatalogue::class);
        $this->app->scoped(FeatureFlags::class);
    }

    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/organization.php'));
        }
    }
}
