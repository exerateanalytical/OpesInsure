<?php

declare(strict_types=1);

namespace App\Application\Claims\Assessment;

use App\Domain\Claims\ClaimTransitionGuard;
use App\Models\Claim;

/**
 * REQ-CLM-010 — blocks the move to DECISION_PENDING until an assessment is accepted and no
 * investigation is open. Tagged 'claims.transition_guards' by ClaimAssessmentServiceProvider
 * only when the ClaimTransitionGuard contract exists.
 */
final class ClaimAssessmentTransitionGuard implements ClaimTransitionGuard
{
    public function __construct(private readonly AssessmentDecisionReadiness $readiness) {}

    public function events(): array
    {
        return AssessmentDecisionReadiness::EVENTS;
    }

    public function check(Claim $claim, string $event, array $context): ?string
    {
        if (! AssessmentDecisionReadiness::targetsDecisionPending($event, $context)) {
            return null;
        }

        return $this->readiness->blockingReason($claim);
    }
}
