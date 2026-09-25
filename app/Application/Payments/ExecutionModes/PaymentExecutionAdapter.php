<?php

declare(strict_types=1);

namespace App\Application\Payments\ExecutionModes;

use App\Application\Capabilities\CapabilityCatalogue;
use App\Application\Distribution\Execution\BaseExecutionAdapter;

/**
 * REQ-AOM-002 / REQ-PAY-014 — base of the five PaymentExecution adapters. MOBILE_MONEY wraps
 * PaymentInitiationService (which reaches the PSP through PaymentAdapterRegistry); the collection modes where
 * OPES does not hold the money record an off-platform receipt that must be reconciled; EXTERNAL_PROVIDER is
 * REMOTE_API (INTEGRATION_UNAVAILABLE until a gateway connector exists).
 */
abstract class PaymentExecutionAdapter extends BaseExecutionAdapter implements PaymentExecution
{
    public function capability(): string
    {
        return self::CAPABILITY;
    }

    public function executionMode(): string
    {
        return CapabilityCatalogue::CAPABILITIES['PAYMENT'][$this->collectionMode()];
    }

    protected function handler(): ?string
    {
        return 'App\\Application\\Finance\\Obligations\\ObligationService::settle';
    }
}
