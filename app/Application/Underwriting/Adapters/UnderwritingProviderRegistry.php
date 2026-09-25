<?php

declare(strict_types=1);

namespace App\Application\Underwriting\Adapters;

use App\Application\Distribution\Execution\AdapterRegistry;

/** REQ-AOM-002 — UnderwritingProvider chosen by CapabilityResolver (or the transaction's pin). */
final class UnderwritingProviderRegistry extends AdapterRegistry
{
    public function capability(): string
    {
        return UnderwritingProvider::CAPABILITY;
    }

    protected function adapters(): array
    {
        return [
            'MANUAL' => ManualUnderwritingProvider::class,
            'CONFIGURED' => ConfiguredUnderwritingProvider::class,
            'HYBRID' => HybridUnderwritingProvider::class,
            'REMOTE_API' => RemoteApiUnderwritingProvider::class,
        ];
    }
}
