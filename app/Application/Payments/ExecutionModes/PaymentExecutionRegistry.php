<?php

declare(strict_types=1);

namespace App\Application\Payments\ExecutionModes;

use App\Application\Distribution\Execution\AdapterRegistry;
use App\Application\Distribution\Execution\ExecutionAdapter;
use InvalidArgumentException;

/**
 * REQ-AOM-002 / REQ-PAY-014 — PaymentExecution chosen by CapabilityResolver (or the payment's pin), like the
 * quote/underwriting/issuance/claim registries. PAYMENT has two MANUAL modes (broker vs insurer collection),
 * so selection goes by the specific collection mode, not only the generic execution mode.
 */
final class PaymentExecutionRegistry extends AdapterRegistry
{
    public const BY_COLLECTION_MODE = [
        'BROKER_COLLECTION' => BrokerCollectionPaymentExecution::class,
        'INSURER_COLLECTION' => InsurerCollectionPaymentExecution::class,
        'MOBILE_MONEY' => MobileMoneyPaymentExecution::class,
        'BANK' => BankPaymentExecution::class,
        'EXTERNAL_PROVIDER' => ExternalProviderPaymentExecution::class,
    ];

    public function capability(): string
    {
        return PaymentExecution::CAPABILITY;
    }

    protected function adapters(): array
    {
        return [
            'MANUAL' => BrokerCollectionPaymentExecution::class,
            'CONFIGURED' => MobileMoneyPaymentExecution::class,
            'HYBRID' => MobileMoneyPaymentExecution::class,
            'REMOTE_API' => ExternalProviderPaymentExecution::class,
        ];
    }

    public function forCollectionMode(string $mode): PaymentExecution
    {
        $normalized = PaymentCollectionModes::normalize($mode) ?? throw new InvalidArgumentException("Unknown payment collection mode {$mode}.");

        return app(self::BY_COLLECTION_MODE[$normalized]);
    }

    public function for(string $carrierId, ?string $productId = null): ExecutionAdapter
    {
        $r = $this->resolver->mode($carrierId, $this->capability(), $productId);

        return $this->forCollectionMode(PaymentCollectionModes::fromResolved($r['mode'], $r['source']));
    }

    public function forSubject(string $subjectType, string $subjectId, string $carrierId, ?string $productId = null): ExecutionAdapter
    {
        $pin = $this->pinner->pinned($subjectType, $subjectId, $this->capability());

        return $pin ? $this->forCollectionMode(PaymentCollectionModes::fromResolved($pin->mode, $pin->source)) : $this->for($carrierId, $productId);
    }
}
