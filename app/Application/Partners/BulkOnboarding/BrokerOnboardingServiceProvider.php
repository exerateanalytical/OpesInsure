<?php

declare(strict_types=1);

namespace App\Application\Partners\BulkOnboarding;

use App\Application\Import\ImportTargetRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** S9 bulk broker onboarding: the broker_onboarding import target and routes/broker_onboarding.php. */
final class BrokerOnboardingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(ImportTargetRegistry::class, fn (ImportTargetRegistry $r) => $r->register(BrokerOnboardingTarget::KEY, BrokerOnboardingTarget::class));
    }

    public function boot(): void
    {
        if ($this->app->resolved(ImportTargetRegistry::class)) {
            $this->app->make(ImportTargetRegistry::class)->register(BrokerOnboardingTarget::KEY, BrokerOnboardingTarget::class);
        }
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/broker_onboarding.php'));
        }
    }
}
