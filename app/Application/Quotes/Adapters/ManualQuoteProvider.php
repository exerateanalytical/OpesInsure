<?php

declare(strict_types=1);

namespace App\Application\Quotes\Adapters;

use App\Application\Distribution\Execution\BaseExecutionAdapter;

/** REQ-AOM-002 — MANUAL QUOTATION adapter. */
final class ManualQuoteProvider extends BaseExecutionAdapter implements QuoteProvider
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
        return null;
    }

    protected function nextAction(): string
    {
        return 'Carrier prices the risk manually and records the offer (carrier quote request queue).';
    }
}
