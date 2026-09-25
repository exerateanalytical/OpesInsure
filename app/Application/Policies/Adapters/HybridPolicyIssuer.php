<?php

declare(strict_types=1);

namespace App\Application\Policies\Adapters;

use App\Application\Distribution\Execution\BaseExecutionAdapter;

/** REQ-AOM-002 — HYBRID POLICY_ISSUANCE adapter. */
final class HybridPolicyIssuer extends BaseExecutionAdapter implements PolicyIssuer
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
        return 'App\\Application\\Policies\\PolicyIssuanceService::approve';
    }

    protected function nextAction(): string
    {
        return 'OPES prepares the policy; the carrier confirms before documents are released.';
    }
}
