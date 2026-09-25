<?php

declare(strict_types=1);

namespace App\Application\Claims\Execution;

use App\Application\Distribution\Execution\ExecutionAdapter;

/** REQ-CLM-011 — claims execution port (`claims` in ExecutionPlanner), chosen per claim by ClaimExecutionRegistry from the pinned CLAIMS_INTAKE mode. */
interface ClaimExecution extends ExecutionAdapter
{
    public const CAPABILITY = 'CLAIMS_INTAKE';

    /** CapabilityPinner subject type (the same pin the claim gets when it is created). */
    public const SUBJECT_TYPE = 'claim';

    /** One of ClaimExecutionModes::MODES. */
    public function claimsMode(): string;
}
