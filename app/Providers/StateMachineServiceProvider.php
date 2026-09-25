<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Shared\StateMachine\Adapters\ClaimMachineAdapter;
use App\Domain\Shared\StateMachine\Contracts\PermissionChecker;
use App\Domain\Shared\StateMachine\Contracts\TransitionEventPublisher;
use App\Domain\Shared\StateMachine\Contracts\TransitionHistoryRecorder;
use App\Domain\Shared\StateMachine\GatePermissionChecker;
use App\Domain\Shared\StateMachine\OutboxTransitionEventPublisher;
use App\Domain\Shared\StateMachine\Recorders\DatabaseTransitionHistoryRecorder;
use App\Domain\Shared\StateMachine\StateMachineEngine;
use App\Domain\Shared\StateMachine\StateMachineRegistry;
use Illuminate\Support\ServiceProvider;

/** REQ-WFL-001 / REQ-ARC-004 wiring. Register in bootstrap/providers.php. */
final class StateMachineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TransitionHistoryRecorder::class, DatabaseTransitionHistoryRecorder::class);
        $this->app->singleton(TransitionEventPublisher::class, OutboxTransitionEventPublisher::class);
        $this->app->singleton(PermissionChecker::class, GatePermissionChecker::class);
        $this->app->singleton(StateMachineEngine::class, fn ($app) => new StateMachineEngine(
            $app->make(TransitionHistoryRecorder::class),
            $app->make(TransitionEventPublisher::class),
            $app->make(PermissionChecker::class),
        ));
        $this->app->singleton(StateMachineRegistry::class, function () {
            $r = new StateMachineRegistry();
            // Bridges only (REQ-DUP-006); canonical claim machine arrives in wave 11A.
            $r->register(ClaimMachineAdapter::STATE_MACHINE, fn () => ClaimMachineAdapter::fromClaimStateMachine());
            $r->register(ClaimMachineAdapter::LIFECYCLE, fn () => ClaimMachineAdapter::fromClaimLifecycle());
            $r->register(\App\Domain\Payments\PaymentMachine::NAME, fn () => \App\Domain\Payments\PaymentMachine::definition()); // REQ-PAY-001
            $r->register(\App\Application\Commissions\Machine\CommissionMachine::NAME, fn () => \App\Application\Commissions\Machine\CommissionMachine::definition()); // REQ-COM-001

            return $r;
        });
    }
}
