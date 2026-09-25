<?php

declare(strict_types=1);

namespace App\Application\Policies\Adapters;

use App\Application\Distribution\Execution\BaseExecutionAdapter;

/** REQ-AOM-002 — REMOTE_API POLICY_ISSUANCE stub: interface only, always INTEGRATION_UNAVAILABLE (no carrier API connected yet). */
final class RemoteApiPolicyIssuer extends BaseExecutionAdapter implements PolicyIssuer
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
        return 'Carrier issuance API not connected; use the fallback mode or issue manually.';
    }
}
