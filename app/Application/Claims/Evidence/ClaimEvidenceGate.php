<?php

declare(strict_types=1);

namespace App\Application\Claims\Evidence;

use App\Models\Claim;

/**
 * REQ-CLM-005: a claim may not move to DECISION_PENDING (legacy stored status CARRIER_REVIEW) while
 * any mandatory evidence item is missing, awaiting review or rejected. Framework-independent so it can
 * be called directly; ClaimEvidenceTransitionGuard adapts it to the claim state machine's guard contract.
 */
final class ClaimEvidenceGate
{
    public const REASON = 'CLAIM_MANDATORY_EVIDENCE_INCOMPLETE';

    /** Target states gated (canonical + the legacy stored status it maps from). */
    public const GATED_STATES = ['DECISION_PENDING', 'CARRIER_REVIEW'];

    public function __construct(private ClaimEvidenceChecklist $checklist) {}

    public function gates(?string $target): bool
    {
        return $target !== null && in_array(strtoupper($target), self::GATED_STATES, true);
    }

    /** null = allowed, else the blocking reason code. */
    public function check(Claim $claim, ?string $target): ?string
    {
        if (! $this->gates($target)) {
            return null;
        }

        return $this->checklist->blocking($claim) === [] ? null : self::REASON;
    }
}
