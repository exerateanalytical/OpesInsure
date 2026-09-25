<?php

declare(strict_types=1);

namespace App\Application\Claims\Execution;

/** REQ-CLM-011 — INSURER_PORTAL claims execution adapter. */
final class InsurerPortalClaimExecution extends ClaimExecutionAdapter
{
    public function claimsMode(): string
    {
        return 'INSURER_PORTAL';
    }

    protected function nextAction(): string
    {
        return 'The claim is lodged on the insurer portal; the portal reference and decision are recorded manually (maker-checker).';
    }
}
