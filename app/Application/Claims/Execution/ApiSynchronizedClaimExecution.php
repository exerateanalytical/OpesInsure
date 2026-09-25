<?php

declare(strict_types=1);

namespace App\Application\Claims\Execution;

/** REQ-CLM-011 — API_SYNCHRONIZED claims execution adapter. */
final class ApiSynchronizedClaimExecution extends ClaimExecutionAdapter
{
    public function claimsMode(): string
    {
        return 'API_SYNCHRONIZED';
    }

    protected function nextAction(): string
    {
        return 'The claim is submitted to the carrier API; acknowledgement and decision arrive as signed carrier messages.';
    }
}
