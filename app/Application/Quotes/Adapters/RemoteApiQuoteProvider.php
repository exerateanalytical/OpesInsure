<?php

declare(strict_types=1);

namespace App\Application\Quotes\Adapters;

use App\Application\Distribution\Execution\BaseExecutionAdapter;

/** REQ-AOM-002 — REMOTE_API QUOTATION stub: interface only, always INTEGRATION_UNAVAILABLE (no carrier API connected yet). */
final class RemoteApiQuoteProvider extends BaseExecutionAdapter implements QuoteProvider
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
        return 'Carrier quotation API not connected; use the fallback mode or request a manual quote.';
    }
}
