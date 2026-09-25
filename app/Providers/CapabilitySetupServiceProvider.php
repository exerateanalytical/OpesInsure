<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Approvals\ApprovalService;
use App\Application\Capabilities\CapabilityPinner;
use App\Application\CarrierOperations\Setup\InsurerActivationApprovalHandler;
use App\Application\Partners\Setup\BrokerActivationApprovalHandler;
use App\Application\Capabilities\CapabilityProfileService;
use App\Application\Capabilities\CapabilityResolver;
use App\Application\CarrierOperations\Setup\CarrierSetupService;
use App\Application\Partners\Setup\PartnerSetupService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * REQ-AOM-001 / REQ-SET-002 / REQ-SET-003 — capability profiles, insurer and broker setup
 * lifecycles; routes/capabilities_setup.php. Needs StateMachineServiceProvider + TemporalServiceProvider.
 */
final class CapabilitySetupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CapabilityResolver::class);
        $this->app->singleton(CapabilityProfileService::class);
        $this->app->singleton(CapabilityPinner::class);
        $this->app->singleton(CarrierSetupService::class);
        $this->app->singleton(PartnerSetupService::class);
    }

    public function boot(): void
    {
        // Activation goes through the central approval inbox (REQ-RBAC-005/006).
        $this->callAfterResolving(ApprovalService::class, function (ApprovalService $approvals): void {
            $approvals->registerHandler('insurer_setup.activate', InsurerActivationApprovalHandler::class);
            $approvals->registerHandler('broker_setup.activate', BrokerActivationApprovalHandler::class);
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/capabilities_setup.php'));
        }
    }
}
