<?php

declare(strict_types=1);

namespace App\Domain\Claims;

/**
 * REQ-CLM-001 extension point (CLAIMS CONTRACT): any module may block a claim transition without editing the machine.
 * Implement this and tag the binding 'claims.transition_guards' in your own service provider. Every tagged guard whose
 * events() include the transition event (or '*') runs before the claim moves; a non-null reason blocks it
 * (App\Application\Claims\ClaimTransitions throws ClaimTransitionBlocked, HTTP 422).
 */
interface ClaimTransitionGuard
{
    /** @return list<string> ClaimMachine event names this guard inspects (e.g. 'approve', 'settle'); '*' = every event. */
    public function events(): array;

    /** @param array<string,mixed> $context from, to, actor_id, reason, details of the attempted transition. Null = ok, else a blocking reason code. */
    public function check(\App\Models\Claim $claim, string $event, array $context): ?string;
}
