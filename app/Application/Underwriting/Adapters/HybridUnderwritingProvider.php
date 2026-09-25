<?php

declare(strict_types=1);

namespace App\Application\Underwriting\Adapters;

use App\Application\Distribution\Execution\BaseExecutionAdapter;

/** REQ-AOM-002 — HYBRID UNDERWRITING adapter. */
final class HybridUnderwritingProvider extends BaseExecutionAdapter implements UnderwritingProvider
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
        return 'App\\Application\\Underwriting\\ProposalService::submit';
    }

    protected function nextAction(): string
    {
        return 'OPES pre-screens disclosures; referrals go to the carrier underwriter.';
    }
}
