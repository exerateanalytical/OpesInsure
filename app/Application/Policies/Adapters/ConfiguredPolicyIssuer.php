<?php

declare(strict_types=1);

namespace App\Application\Policies\Adapters;

use App\Application\Distribution\Execution\BaseExecutionAdapter;

/** REQ-AOM-002 — CONFIGURED POLICY_ISSUANCE adapter. */
final class ConfiguredPolicyIssuer extends BaseExecutionAdapter implements PolicyIssuer
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
        return 'App\\Application\\Policies\\PolicyIssuanceService::approve';
    }

    protected function nextAction(): string
    {
        return 'OPES issues the policy and generates documents from the carrier template (document engine).';
    }
}
