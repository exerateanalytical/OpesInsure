<?php

declare(strict_types=1);

namespace App\Application\Claims\Adapters;

use App\Application\Distribution\Execution\AdapterRegistry;

/** REQ-AOM-002 — ClaimProvider chosen by CapabilityResolver (or the transaction's pin). */
final class ClaimProviderRegistry extends AdapterRegistry
{
    public function capability(): string
    {
        return ClaimProvider::CAPABILITY;
    }

    protected function adapters(): array
    {
        return [
            'MANUAL' => ManualClaimProvider::class,
            'CONFIGURED' => ConfiguredClaimProvider::class,
            'HYBRID' => HybridClaimProvider::class,
            'REMOTE_API' => RemoteApiClaimProvider::class,
        ];
    }
}
