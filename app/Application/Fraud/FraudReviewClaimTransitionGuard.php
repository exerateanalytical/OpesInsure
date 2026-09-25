<?php

declare(strict_types=1);

namespace App\Application\Fraud;

use App\Domain\Claims\ClaimTransitionGuard;
use App\Models\Claim;

/**
 * REQ-FRD-001 — ClaimTransitionGuard (agent C1 contract): blocks APPROVED / SETTLEMENT while a fraud review is open.
 * Only loaded when the interface exists (FraudServiceProvider tags it 'claims.transition_guards').
 */
final class FraudReviewClaimTransitionGuard implements ClaimTransitionGuard
{
    public function __construct(private readonly ClaimFraudHold $hold) {}

    public function events(): array
    {
        return ['approve', 'partially_approve', 'partial_approve', 'request_settlement', 'settle', 'approve_settlement',
            'APPROVE', 'PARTIALLY_APPROVE', 'PARTIAL_APPROVE', 'REQUEST_SETTLEMENT', 'SETTLE', 'APPROVE_SETTLEMENT'];
    }

    public function check(Claim $claim, string $event, array $context): ?string
    {
        return $this->hold->check($claim, $event, $context);
    }
}
