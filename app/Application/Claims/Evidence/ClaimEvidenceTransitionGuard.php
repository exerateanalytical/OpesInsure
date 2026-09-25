<?php

declare(strict_types=1);

namespace App\Application\Claims\Evidence;

use App\Domain\Claims\ClaimTransitionGuard;
use App\Models\Claim;

/**
 * Claims-contract guard (tagged 'claims.transition_guards' by ClaimEvidenceServiceProvider only when the
 * ClaimTransitionGuard interface exists). Blocks the move to DECISION_PENDING while mandatory evidence is
 * missing/unreviewed. The target state is taken from $context['to'] / ['to_state'] / ['target'], else the
 * event name itself when it is a state.
 */
final class ClaimEvidenceTransitionGuard implements ClaimTransitionGuard
{
    /** The ClaimMachine event that leads to DECISION_PENDING (stored CARRIER_REVIEW). */
    public const EVENTS = ['refer_for_decision'];

    public function __construct(private ClaimEvidenceGate $gate) {}

    public function events(): array
    {
        return self::EVENTS;
    }

    public function check(Claim $claim, string $event, array $context): ?string
    {
        $to = $context['to'] ?? $context['to_state'] ?? $context['target'] ?? null;
        if ($to === null && $this->gate->gates($event)) {
            $to = $event;
        }
        if ($to === null) {
            // Unknown target: the named decision events always head to DECISION_PENDING.
            $to = in_array($event, self::EVENTS, true) ? 'DECISION_PENDING' : null;
        }

        return $this->gate->check($claim, is_string($to) ? $to : null);
    }
}
