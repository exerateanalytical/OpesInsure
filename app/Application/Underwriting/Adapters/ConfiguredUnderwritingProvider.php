<?php

declare(strict_types=1);

namespace App\Application\Underwriting\Adapters;

use App\Application\Distribution\Execution\BaseExecutionAdapter;

/** REQ-AOM-002 — CONFIGURED UNDERWRITING adapter. */
final class ConfiguredUnderwritingProvider extends BaseExecutionAdapter implements UnderwritingProvider
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
        return 'App\\Application\\Underwriting\\ProposalService::submit';
    }

    protected function nextAction(): string
    {
        return 'OPES rule engine decides: straight-through when no disclosure raises a referral flag, otherwise referral.';
    }
}
