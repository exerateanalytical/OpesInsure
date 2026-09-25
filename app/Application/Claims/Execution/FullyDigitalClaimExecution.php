<?php

declare(strict_types=1);

namespace App\Application\Claims\Execution;

/** REQ-CLM-011 — FULLY_DIGITAL claims execution adapter. */
final class FullyDigitalClaimExecution extends ClaimExecutionAdapter
{
    public function claimsMode(): string
    {
        return 'FULLY_DIGITAL';
    }

    protected function handler(): ?string
    {
        return 'App\\Application\\Claims\\ClaimLifecycleService::fnol';
    }

    protected function nextAction(): string
    {
        return 'OPES claims workflow registers, assesses and decides the claim under delegated authority.';
    }
}
