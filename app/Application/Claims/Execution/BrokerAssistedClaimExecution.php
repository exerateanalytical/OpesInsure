<?php

declare(strict_types=1);

namespace App\Application\Claims\Execution;

/** REQ-CLM-011 — BROKER_ASSISTED claims execution adapter. */
final class BrokerAssistedClaimExecution extends ClaimExecutionAdapter
{
    public function claimsMode(): string
    {
        return 'BROKER_ASSISTED';
    }

    protected function nextAction(): string
    {
        return 'The broker submits the claim to the carrier; carrier acknowledgement and decision are keyed in by broker staff (maker-checker).';
    }
}
