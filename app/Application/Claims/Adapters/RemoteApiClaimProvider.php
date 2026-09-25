<?php

declare(strict_types=1);

namespace App\Application\Claims\Adapters;

use App\Application\Distribution\Execution\BaseExecutionAdapter;

/** REQ-AOM-002 — REMOTE_API CLAIMS_INTAKE stub: interface only, always INTEGRATION_UNAVAILABLE (no carrier API connected yet). */
final class RemoteApiClaimProvider extends BaseExecutionAdapter implements ClaimProvider
{
    public function capability(): string
    {
        return self::CAPABILITY;
    }

    public function executionMode(): string
    {
        return 'REMOTE_API';
    }

    protected function handler(): ?string
    {
        return null;
    }

    protected function nextAction(): string
    {
        return 'Carrier claims API not connected; use the fallback mode or forward the claim manually.';
    }
}
