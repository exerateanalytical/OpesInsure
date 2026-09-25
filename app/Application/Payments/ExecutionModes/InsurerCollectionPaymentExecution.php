<?php

declare(strict_types=1);

namespace App\Application\Payments\ExecutionModes;

/** REQ-PAY-014 — INSURER_COLLECTION PaymentExecution adapter (MANUAL). */
final class InsurerCollectionPaymentExecution extends PaymentExecutionAdapter
{
    public function collectionMode(): string
    {
        return 'INSURER_COLLECTION';
    }

    protected function nextAction(): string
    {
        return 'Carrier collects the premium directly; record the carrier receipt confirmation and reconcile.';
    }
}
