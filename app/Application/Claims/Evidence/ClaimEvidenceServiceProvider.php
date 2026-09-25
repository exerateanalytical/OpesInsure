<?php

declare(strict_types=1);

namespace App\Application\Claims\Evidence;

use Illuminate\Support\ServiceProvider;

/** REQ-CLM-005 (agent C6): registers the evidence guard with the claim state machine once its contract exists. */
final class ClaimEvidenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (interface_exists(\App\Domain\Claims\ClaimTransitionGuard::class)) {
            $this->app->tag([ClaimEvidenceTransitionGuard::class], 'claims.transition_guards');
        }
    }
}
