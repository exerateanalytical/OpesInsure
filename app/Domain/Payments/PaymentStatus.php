<?php

declare(strict_types=1);

namespace App\Domain\Payments;

enum PaymentStatus: string
{
    case Created = 'CREATED';
    case PendingCustomer = 'PENDING_CUSTOMER';
    case Processing = 'PROCESSING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Expired = 'EXPIRED';
    case RefundPending = 'REFUND_PENDING';
    case Refunded = 'REFUNDED';
    case ChargebackOpen = 'CHARGEBACK_OPEN';
    case ChargedBack = 'CHARGED_BACK';
}
