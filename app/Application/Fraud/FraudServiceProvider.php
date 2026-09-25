<?php

declare(strict_types=1);

namespace App\Application\Fraud;

use Illuminate\Support\ServiceProvider;

/** REQ-FRD-001 / REQ-FRD-002 wiring (agent C16). */
final class FraudServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RuleConditionEvaluator::class);
        if (interface_exists(\App\Domain\Claims\ClaimTransitionGuard::class)) {
            $this->app->bind(FraudReviewClaimTransitionGuard::class);
            $this->app->tag([FraudReviewClaimTransitionGuard::class], 'claims.transition_guards');
        }
    }
}
