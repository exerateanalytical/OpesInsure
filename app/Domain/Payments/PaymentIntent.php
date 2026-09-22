<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Shared\Money;
use DomainException;

final class PaymentIntent
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $proposalId,
        public readonly Money $amount,
        private PaymentStatus $status = PaymentStatus::Created,
        private ?string $providerReference = null,
    ) {}

    public function requestCustomerAuthorization(string $providerReference): void
    {
        $this->requireStatus(PaymentStatus::Created);
        $this->providerReference = $providerReference;
        $this->status = PaymentStatus::PendingCustomer;
    }

    public function confirm(): void
    {
        if (! in_array($this->status, [PaymentStatus::PendingCustomer, PaymentStatus::Processing], true)) {
            throw new DomainException('Payment cannot be confirmed from its current state.');
        }
        $this->status = PaymentStatus::Succeeded;
    }

    public function status(): PaymentStatus { return $this->status; }

    private function requireStatus(PaymentStatus $expected): void
    {
        if ($this->status !== $expected) {
            throw new DomainException("Expected {$expected->value}; found {$this->status->value}.");
        }
    }
}
