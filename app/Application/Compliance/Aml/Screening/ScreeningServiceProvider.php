<?php

declare(strict_types=1);

namespace App\Application\Compliance\Aml\Screening;

use App\Application\Compliance\Aml\Screening\Console\AmlRescreenCommand;
use App\Models\Party;
use App\Models\TenantCustomer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Agent E8 — REQ-AML-001 / REQ-KYC-004 wiring: claims payout guard, onboarding screening when a party becomes a tenant
 * customer (only when the tenant has an active list), daily aml:rescreen (no-op until an interval is configured).
 */
final class ScreeningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (interface_exists(\App\Domain\Claims\ClaimTransitionGuard::class)) {
            $this->app->bind(ScreeningClaimTransitionGuard::class);
            $this->app->tag([ScreeningClaimTransitionGuard::class], 'claims.transition_guards');
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([AmlRescreenCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('aml:rescreen')->dailyAt('02:45')->withoutOverlapping()->onOneServer();
        });
        TenantCustomer::created(function (TenantCustomer $tc): void {
            rescue(function () use ($tc) {
                $svc = $this->app->make(ScreeningService::class);
                if ($svc->hasActiveLists($tc->tenant_id) && ($party = Party::find($tc->party_id))) {
                    $svc->screenParty($tc->tenant_id, $party, 'ONBOARDING');
                }
            });
        });
    }
}
