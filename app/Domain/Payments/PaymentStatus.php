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
    // REQ-PAY-001 additions (PaymentMachine holds the blueprint mapping)
    case AwaitingTransfer = 'AWAITING_TRANSFER';
    case Cancelled = 'CANCELLED';
    case Reversed = 'REVERSED';

    public function blueprint(bool $reconciled = false): string
    {
        return PaymentMachine::blueprintState($this->value, $reconciled);
    }

    public function canBecome(self $to): bool
    {
        return PaymentMachine::definition()->transitionTo($this->value, $to->value) !== null;
    }
}
