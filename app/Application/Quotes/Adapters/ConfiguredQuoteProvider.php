<?php

declare(strict_types=1);

namespace App\Application\Quotes\Adapters;

use App\Application\Distribution\Execution\BaseExecutionAdapter;

/** REQ-AOM-002 — CONFIGURED QUOTATION adapter. */
final class ConfiguredQuoteProvider extends BaseExecutionAdapter implements QuoteProvider
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
        return 'App\\Application\\Quotes\\QuoteService::rate';
    }

    protected function nextAction(): string
    {
        return 'OPES rates the quote with the carrier\'s published tariff (DeterministicRatingEngine).';
    }
}
