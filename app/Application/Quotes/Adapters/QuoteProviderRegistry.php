<?php

declare(strict_types=1);

namespace App\Application\Quotes\Adapters;

use App\Application\Distribution\Execution\AdapterRegistry;

/** REQ-AOM-002 — QuoteProvider chosen by CapabilityResolver (or the transaction's pin). */
final class QuoteProviderRegistry extends AdapterRegistry
{
    public function capability(): string
    {
        return QuoteProvider::CAPABILITY;
    }

    protected function adapters(): array
    {
        return [
            'MANUAL' => ManualQuoteProvider::class,
            'CONFIGURED' => ConfiguredQuoteProvider::class,
            'HYBRID' => HybridQuoteProvider::class,
            'REMOTE_API' => RemoteApiQuoteProvider::class,
        ];
    }
}
