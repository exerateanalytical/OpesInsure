<?php

declare(strict_types=1);

namespace App\Application\Claims\Assessment;

use App\Domain\Claims\ClaimTransitionGuard;
use Illuminate\Support\ServiceProvider;

/** REQ-CLM-010 — registers the assessment transition guard on the claims guard tag (when the contract exists). */
final class ClaimAssessmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (interface_exists(ClaimTransitionGuard::class)) {
            $this->app->tag([ClaimAssessmentTransitionGuard::class], 'claims.transition_guards');
        }
    }
}
