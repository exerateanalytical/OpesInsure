<?php

declare(strict_types=1);

namespace App\Application\Quotes\Adapters;

use App\Application\Distribution\Execution\BaseExecutionAdapter;

/** REQ-AOM-002 — HYBRID QUOTATION adapter. */
final class HybridQuoteProvider extends BaseExecutionAdapter implements QuoteProvider
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
        return 'App\\Application\\Quotes\\QuoteService::rate';
    }

    protected function nextAction(): string
    {
        return 'OPES computes an indicative premium; the carrier confirms or amends the offer.';
    }
}
