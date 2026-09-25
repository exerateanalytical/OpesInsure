<?php

declare(strict_types=1);

namespace App\Application\Underwriting\Adapters;

use App\Application\Distribution\Execution\BaseExecutionAdapter;

/** REQ-AOM-002 — REMOTE_API UNDERWRITING stub: interface only, always INTEGRATION_UNAVAILABLE (no carrier API connected yet). */
final class RemoteApiUnderwritingProvider extends BaseExecutionAdapter implements UnderwritingProvider
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
        return 'Carrier underwriting API not connected; use the fallback mode or refer to the carrier underwriter.';
    }
}
