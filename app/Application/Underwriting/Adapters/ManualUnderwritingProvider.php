<?php

declare(strict_types=1);

namespace App\Application\Underwriting\Adapters;

use App\Application\Distribution\Execution\BaseExecutionAdapter;

/** REQ-AOM-002 — MANUAL UNDERWRITING adapter. */
final class ManualUnderwritingProvider extends BaseExecutionAdapter implements UnderwritingProvider
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
        return 'App\\Application\\Underwriting\\UnderwritingService::decide';
    }

    protected function nextAction(): string
    {
        return 'Carrier underwriter reviews the proposal and records the decision.';
    }
}
