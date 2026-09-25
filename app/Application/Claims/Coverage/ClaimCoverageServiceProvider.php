<?php

declare(strict_types=1);

namespace App\Application\Claims\Coverage;

use Illuminate\Support\ServiceProvider;

/** REQ-CLM-003 — registers the coverage approval guard with the claim state machine (when its contract exists). */
final class ClaimCoverageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (interface_exists(\App\Domain\Claims\ClaimTransitionGuard::class)) {
            $this->app->tag([ClaimCoverageTransitionGuard::class], 'claims.transition_guards');
        }
    }
}
