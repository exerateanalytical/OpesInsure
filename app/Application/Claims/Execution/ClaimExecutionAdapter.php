<?php

declare(strict_types=1);

namespace App\Application\Claims\Execution;

use App\Application\Capabilities\CapabilityCatalogue;
use App\Application\Distribution\Execution\BaseExecutionAdapter;

/**
 * REQ-CLM-011 — base of the five claims execution adapters. The generic execution mode comes from the
 * capability catalogue (CLAIMS_INTAKE), so the outcome status (AWAITING_CARRIER / HANDLED_BY_PLATFORM /
 * INTEGRATION_UNAVAILABLE for outbound API dispatch) follows the shared BaseExecutionAdapter rules.
 */
abstract class ClaimExecutionAdapter extends BaseExecutionAdapter implements ClaimExecution
{
    public function capability(): string
    {
        return self::CAPABILITY;
    }

    public function executionMode(): string
    {
        return CapabilityCatalogue::CAPABILITIES[self::CAPABILITY][$this->claimsMode()];
    }

    protected function handler(): ?string
    {
        return 'App\\Application\\Claims\\ClaimCarrierExchangeService::queue';
    }
}
