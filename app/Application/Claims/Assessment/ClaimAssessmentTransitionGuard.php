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
        // An appeal goes back to DECISION_PENDING from APPEALED: the assessment gate was already passed by the
        // original decision, and the appeal is judged on the dispute (C12), not on a fresh assessment.
        if (($context['from'] ?? null) === 'APPEALED') {
            return null;
        }

        return $this->readiness->blockingReason($claim);
    }
}
