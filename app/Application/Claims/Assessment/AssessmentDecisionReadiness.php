<?php

declare(strict_types=1);

namespace App\Application\Claims\Assessment;

use App\Models\Claim;

/**
 * REQ-CLM-010 — can a claim move to DECISION_PENDING? Needs an ACCEPTED assessment and no OPEN investigation.
 * Kept separate from the guard so it works before the ClaimTransitionGuard contract exists.
 */
final class AssessmentDecisionReadiness
{
    public const TARGET_STATE = 'DECISION_PENDING';

    /** Event names that lead to DECISION_PENDING (the claim machine may also pass the target state in context). */
    public const EVENTS = ['refer_for_decision']; // ClaimMachine event whose target is DECISION_PENDING (stored CARRIER_REVIEW)

    public function __construct(private readonly ClaimAssessmentService $assessments, private readonly ClaimInvestigationService $investigations) {}

    /** @return string|null null = ready, else a blocking reason code */
    public function blockingReason(Claim $claim): ?string
    {
        if (! $this->assessments->hasAccepted($claim)) {
            return 'CLAIM_ASSESSMENT_REQUIRED';
        }
        if ($this->investigations->hasOpen($claim)) {
            return 'CLAIM_INVESTIGATION_OPEN';
        }

        return null;
    }

    /** Does this transition lead into DECISION_PENDING? Uses the target in context (to/target/to_state/to_status) or the event name. */
    public static function targetsDecisionPending(string $event, array $context): bool
    {
        foreach (['to', 'target', 'to_state', 'to_status'] as $k) {
            if (isset($context[$k]) && is_string($context[$k])) {
                return strtoupper($context[$k]) === self::TARGET_STATE;
            }
        }

        return in_array($event, self::EVENTS, true);
    }
}
