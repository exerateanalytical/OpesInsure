<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Underwriting\ProposalMachine;
use App\Domain\Shared\StateMachine\StateMachineRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Batch 6D — proposal machine registration + lifecycle routes (REQ-PRP-001…005). */
final class ProposalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(StateMachineRegistry::class, fn (StateMachineRegistry $r) => $r->has(ProposalMachine::NAME) ?: $r->register(ProposalMachine::NAME, fn () => ProposalMachine::definition()));
    }

    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/proposals.php'));
        }
    }
}
