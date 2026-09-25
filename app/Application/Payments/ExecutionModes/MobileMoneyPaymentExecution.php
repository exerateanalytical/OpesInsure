<?php

declare(strict_types=1);

namespace App\Application\Payments\ExecutionModes;

/** REQ-PAY-014 — MOBILE_MONEY PaymentExecution adapter (CONFIGURED): wraps PaymentInitiationService. */
final class MobileMoneyPaymentExecution extends PaymentExecutionAdapter
{
    public function collectionMode(): string
    {
        return 'MOBILE_MONEY';
    }

    protected function handler(): ?string
    {
        return 'App\\Application\\Payments\\PaymentInitiationService::initiate';
    }

    protected function nextAction(): string
    {
        return 'OPES prompts the payer through the mobile-money provider; the provider webhook confirms the payment.';
    }
}
