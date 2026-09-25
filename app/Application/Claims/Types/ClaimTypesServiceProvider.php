<?php

declare(strict_types=1);

namespace App\Application\Claims\Types;

use Illuminate\Support\ServiceProvider;

/** REQ-CLM-007 (agent C8) — registers the late-claim guard with the claim state machine (when its contract exists). */
final class ClaimTypesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (interface_exists(\App\Domain\Claims\ClaimTransitionGuard::class)) {
            $this->app->tag([LateClaimTransitionGuard::class], 'claims.transition_guards');
        }
    }
}
