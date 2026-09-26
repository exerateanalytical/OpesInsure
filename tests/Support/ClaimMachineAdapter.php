<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Shared\StateMachine\Adapters\LegacyTransitionTableAdapter;

use App\Domain\Claims\ClaimLifecycle;
use App\Domain\Claims\ClaimStateMachine;
use App\Domain\Shared\StateMachine\StateMachineDefinition;

/**
 * Test-only since Batch 11 retired the legacy claim machines (canonical ClaimMachine is the runtime path).
 * REQ-DUP-006 bridge: proves both existing claim definitions run on the generic engine.
 * Neither legacy class is modified or deleted; wave 11A (REQ-CLM-001) chooses the single canonical
 * blueprint-state definition and retires the other.
 *
 * Known divergence (reported, not resolved here): ClaimLifecycle adds PAYMENT_PENDING and CLOSED->REOPENED->ASSESSMENT;
 * ClaimStateMachine goes APPROVED->PAID directly and treats CLOSED as terminal.
 */
final class ClaimMachineAdapter
{
    public const STATE_MACHINE = 'claim.legacy_state_machine';
    public const LIFECYCLE = 'claim.legacy_lifecycle';

    public static function fromClaimStateMachine(): StateMachineDefinition
    {
        return LegacyTransitionTableAdapter::fromClassConstant(ClaimStateMachine::class, 'T', self::STATE_MACHINE, 'DRAFT', 'claim');
    }

    public static function fromClaimLifecycle(): StateMachineDefinition
    {
        return LegacyTransitionTableAdapter::fromClassConstant(ClaimLifecycle::class, 'TRANSITIONS', self::LIFECYCLE, 'DRAFT', 'claim');
    }
}
