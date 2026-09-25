<?php

declare(strict_types=1);

namespace App\Application\Policies\Adapters;

use App\Application\Distribution\Execution\AdapterRegistry;

/** REQ-AOM-002 — PolicyIssuer chosen by CapabilityResolver (or the transaction's pin). */
final class PolicyIssuerRegistry extends AdapterRegistry
{
    public function capability(): string
    {
        return PolicyIssuer::CAPABILITY;
    }

    protected function adapters(): array
    {
        return [
            'MANUAL' => ManualPolicyIssuer::class,
            'CONFIGURED' => ConfiguredPolicyIssuer::class,
            'HYBRID' => HybridPolicyIssuer::class,
            'REMOTE_API' => RemoteApiPolicyIssuer::class,
        ];
    }
}
