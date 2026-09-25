<?php

declare(strict_types=1);

namespace App\Application\Claims\Adapters;

use App\Application\Distribution\Execution\BaseExecutionAdapter;

/** REQ-AOM-002 — CONFIGURED CLAIMS_INTAKE adapter. */
final class ConfiguredClaimProvider extends BaseExecutionAdapter implements ClaimProvider
{
    public function capability(): string
    {
        return self::CAPABILITY;
    }

    public function executionMode(): string
    {
        return 'CONFIGURED';
    }

    protected function handler(): ?string
    {
        return 'App\\Application\\Claims\\ClaimLifecycleService::fnol';
    }

    protected function nextAction(): string
    {
        return 'OPES claims workflow registers and triages the claim.';
    }
}
