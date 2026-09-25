<?php

declare(strict_types=1);

namespace App\Application\Policies\Adapters;

use App\Application\Distribution\Execution\BaseExecutionAdapter;

/** REQ-AOM-002 — MANUAL POLICY_ISSUANCE adapter. */
final class ManualPolicyIssuer extends BaseExecutionAdapter implements PolicyIssuer
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
        return 'App\\Application\\Policies\\PolicyIssuanceService::approve';
    }

    protected function nextAction(): string
    {
        return 'Carrier issues the policy and uploads the original documents; OPES records the carrier reference.';
    }
}
