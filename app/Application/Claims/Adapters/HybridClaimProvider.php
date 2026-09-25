<?php

declare(strict_types=1);

namespace App\Application\Claims\Adapters;

use App\Application\Distribution\Execution\BaseExecutionAdapter;

/** REQ-AOM-002 — HYBRID CLAIMS_INTAKE adapter. */
final class HybridClaimProvider extends BaseExecutionAdapter implements ClaimProvider
{
    public function capability(): string
    {
        return self::CAPABILITY;
    }

    public function executionMode(): string
    {
        return 'HYBRID';
    }

    protected function handler(): ?string
    {
        return 'App\\Application\\Claims\\ClaimLifecycleService::fnol';
    }

    protected function nextAction(): string
    {
        return 'OPES registers the claim; the carrier acknowledges and decides.';
    }
}
