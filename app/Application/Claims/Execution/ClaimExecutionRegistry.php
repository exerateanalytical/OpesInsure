<?php

declare(strict_types=1);

namespace App\Application\Claims\Execution;

use App\Application\Distribution\Execution\AdapterRegistry;
use App\Application\Distribution\Execution\ExecutionAdapter;
use InvalidArgumentException;

/**
 * REQ-CLM-011 — the `claims` execution port. CLAIMS_INTAKE has three MANUAL modes (broker-assisted, manual
 * carrier, insurer portal), so selection goes by the specific claims mode of the claim's pin, like payments.
 */
final class ClaimExecutionRegistry extends AdapterRegistry
{
    public const BY_CLAIMS_MODE = [
        'BROKER_ASSISTED' => BrokerAssistedClaimExecution::class,
        'MANUAL_CARRIER' => ManualCarrierClaimExecution::class,
        'INSURER_PORTAL' => InsurerPortalClaimExecution::class,
        'API_SYNCHRONIZED' => ApiSynchronizedClaimExecution::class,
        'FULLY_DIGITAL' => FullyDigitalClaimExecution::class,
    ];

    public function capability(): string
    {
        return ClaimExecution::CAPABILITY;
    }

    protected function adapters(): array
    {
        return [
            'MANUAL' => BrokerAssistedClaimExecution::class,
            'CONFIGURED' => FullyDigitalClaimExecution::class,
            'HYBRID' => InsurerPortalClaimExecution::class,
            'REMOTE_API' => ApiSynchronizedClaimExecution::class,
        ];
    }

    public function forClaimsMode(string $mode): ClaimExecution
    {
        $normalized = ClaimExecutionModes::normalize($mode) ?? throw new InvalidArgumentException("Unknown claims execution mode {$mode}.");

        return app(self::BY_CLAIMS_MODE[$normalized]);
    }

    public function for(string $carrierId, ?string $productId = null): ExecutionAdapter
    {
        $r = $this->resolver->mode($carrierId, $this->capability(), $productId);

        return $this->forClaimsMode(ClaimExecutionModes::fromResolved($r['mode'], $r['source']));
    }

    public function forSubject(string $subjectType, string $subjectId, string $carrierId, ?string $productId = null): ExecutionAdapter
    {
        $pin = $this->pinner->pinned($subjectType, $subjectId, $this->capability());

        return $pin ? $this->forClaimsMode(ClaimExecutionModes::fromResolved($pin->mode, $pin->source)) : $this->for($carrierId, $productId);
    }
}
