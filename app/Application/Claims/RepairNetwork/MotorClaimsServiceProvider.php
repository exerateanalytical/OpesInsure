<?php

declare(strict_types=1);

namespace App\Application\Claims\RepairNetwork;

use App\Application\Claims\RepairNetwork\Import\RepairGarageTarget;
use App\Application\Claims\RepairNetwork\Import\TechnicalExpertTarget;
use App\Application\Import\ImportTargetRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Agent GP3 — gap closure pack 03: routes, import targets (repair_garages, technical_experts), data-readiness gates. */
final class MotorClaimsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportTargetRegistry::class, function (ImportTargetRegistry $r): void {
            $r->register('repair_garages', RepairGarageTarget::class);
            $r->register('technical_experts', TechnicalExpertTarget::class);
        });
    }

    public function boot(): void
    {
        MotorClaimsReadiness::register();
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/gap_closure_motor_claims.php'));
        }
    }
}
