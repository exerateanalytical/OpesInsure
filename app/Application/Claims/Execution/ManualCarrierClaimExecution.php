<?php

declare(strict_types=1);

namespace App\Application\Claims\Execution;

/** REQ-CLM-011 — MANUAL_CARRIER claims execution adapter. */
final class ManualCarrierClaimExecution extends ClaimExecutionAdapter
{
    public function claimsMode(): string
    {
        return 'MANUAL_CARRIER';
    }

    protected function nextAction(): string
    {
        return 'The claim is sent to the carrier claims desk; its acknowledgement and decision are entered manually (maker-checker).';
    }
}
