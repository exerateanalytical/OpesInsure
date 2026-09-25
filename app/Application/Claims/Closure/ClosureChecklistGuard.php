<?php

declare(strict_types=1);

namespace App\Application\Claims\Closure;

use App\Models\Claim;

/**
 * REQ-CLM-014: blocks the claim "close" transition until the closure checklist passes.
 * Implements App\Domain\Claims\ClaimTransitionGuard (owned by agent C1) — registered/tagged
 * 'claims.transition_guards' by ClaimClosureServiceProvider only when that interface exists.
 * Context key 'closure_reason' (or 'reason_code') exempts WITHDRAWN/DUPLICATE/OPENED_IN_ERROR from DECISION_RECORDED.
 */
if (interface_exists(\App\Domain\Claims\ClaimTransitionGuard::class)) {
    final class ClosureChecklistGuard implements \App\Domain\Claims\ClaimTransitionGuard
    {
        public function __construct(private ClaimClosureChecklist $checklist) {}

        public function events(): array
        {
            return ['close'];
        }

        public function check(Claim $claim, string $event, array $context): ?string
        {
            if (isset($context['to']) && is_string($context['to']) && strtoupper($context['to']) !== 'CLOSED') {
                return null;
            }

            return $this->checklist->firstFailure($claim, $context['closure_reason'] ?? $context['reason_code'] ?? null);
        }
    }
}
