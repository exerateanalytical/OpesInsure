<?php

declare(strict_types=1);

namespace App\Application\Payments\ExecutionModes;

/** REQ-PAY-014 — BANK PaymentExecution adapter (CONFIGURED). */
final class BankPaymentExecution extends PaymentExecutionAdapter
{
    public function collectionMode(): string
    {
        return 'BANK';
    }

    protected function nextAction(): string
    {
        return 'Payer pays by bank transfer or deposit to the collection account; match the bank statement line to the payment.';
    }
}
