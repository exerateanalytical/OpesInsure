<?php

declare(strict_types=1);

namespace App\Application\OperationsTaxonomy;

use App\Application\Import\ImportTargetRegistry;
use App\Application\OperationsTaxonomy\Targets\RetentionScheduleTarget;
use App\Application\OperationsTaxonomy\Targets\SlaProfileTarget;
use App\Application\PrivateOnboarding\Targets\PrivateOnboardingTarget;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Gap Closure Pack v1 files 10 + 11: routes, ImportPipeline targets, Data Readiness gates. */
final class OperationsGapClosureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(ImportTargetRegistry::class, function (ImportTargetRegistry $r): void {
            $r->register('private_onboarding', PrivateOnboardingTarget::class);
            $r->register('sla_profiles', SlaProfileTarget::class);
            $r->register('retention_schedules', RetentionScheduleTarget::class);
        });
    }

    public function boot(): void
    {
        OperationsReadiness::register();
        Route::middleware('api')->prefix('api')->group(base_path('routes/operations_gap_closure.php'));
    }
}
