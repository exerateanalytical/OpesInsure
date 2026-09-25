<?php

declare(strict_types=1);

namespace App\Application\Payments\ExecutionModes;

/** REQ-PAY-014 — BROKER_COLLECTION PaymentExecution adapter (MANUAL). */
final class BrokerCollectionPaymentExecution extends PaymentExecutionAdapter
{
    public function collectionMode(): string
    {
        return 'BROKER_COLLECTION';
    }

    protected function nextAction(): string
    {
        return 'Broker collects the premium into its premium trust account; record the receipt with evidence and remit to the carrier.';
    }
}
