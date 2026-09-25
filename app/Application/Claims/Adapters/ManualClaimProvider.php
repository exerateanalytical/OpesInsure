<?php

declare(strict_types=1);

namespace App\Application\Claims\Adapters;

use App\Application\Distribution\Execution\BaseExecutionAdapter;

/** REQ-AOM-002 — MANUAL CLAIMS_INTAKE adapter. */
final class ManualClaimProvider extends BaseExecutionAdapter implements ClaimProvider
{
    public function capability(): string
    {
        return self::CAPABILITY;
    }

    public function executionMode(): string
    {
        return 'MANUAL';
    }

    protected function handler(): ?string
    {
        return 'App\\Application\\Claims\\ClaimCarrierExchangeService::queue';
    }

    protected function nextAction(): string
    {
        return 'Claim is forwarded to the carrier (carrier exchange message) and handled by the carrier claims desk.';
    }
}
