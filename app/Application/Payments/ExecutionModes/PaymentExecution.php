<?php

declare(strict_types=1);

namespace App\Application\Payments\ExecutionModes;

use App\Application\Distribution\Execution\ExecutionAdapter;

/** REQ-AOM-002 / REQ-PAY-014 — PAYMENT execution port, chosen by PaymentExecutionRegistry (capability-pinned). */
interface PaymentExecution extends ExecutionAdapter
{
    public const CAPABILITY = 'PAYMENT';

    /** CapabilityPinner subject type pinned when the payment's collection mode is assigned. */
    public const SUBJECT_TYPE = 'payment_intent';

    /** One of PaymentCollectionModes::MODES. */
    public function collectionMode(): string;
}
