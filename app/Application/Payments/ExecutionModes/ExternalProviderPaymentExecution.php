<?php

declare(strict_types=1);

namespace App\Application\Payments\ExecutionModes;

/** REQ-PAY-014 — EXTERNAL_PROVIDER PaymentExecution adapter (REMOTE_API, INTEGRATION_UNAVAILABLE). */
final class ExternalProviderPaymentExecution extends PaymentExecutionAdapter
{
    public function collectionMode(): string
    {
        return 'EXTERNAL_PROVIDER';
    }

    protected function handler(): ?string
    {
        return null;
    }

    protected function nextAction(): string
    {
        return 'External payment gateway integration is not connected yet; use the fallback collection mode.';
    }
}
