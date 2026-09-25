<?php

declare(strict_types=1);

namespace App\Application\Claims\Coverage;

use App\Domain\Claims\ClaimTransitionGuard;
use App\Models\Claim;

/**
 * REQ-CLM-003 — blocks claim approval while the latest coverage check is not COVERAGE_CONFIRMED and has no
 * human resolution. Tagged 'claims.transition_guards' by ClaimCoverageServiceProvider only when the
 * ClaimTransitionGuard contract (owned by agent C1) exists.
 */
final class ClaimCoverageTransitionGuard implements ClaimTransitionGuard
{
    public function __construct(private readonly ClaimCoverageCheckService $checks) {}

    public function events(): array
    {
        return ClaimCoverageCheckService::APPROVAL_EVENTS;
    }

    public function check(Claim $claim, string $event, array $context): ?string
    {
        return in_array($event, ClaimCoverageCheckService::APPROVAL_EVENTS, true) ? $this->checks->approvalBlocker($claim) : null;
    }
}
