<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use DomainException;

final readonly class Money
{
    public function __construct(public int $minor, public string $currency = 'XAF')
    {
        if ($minor < 0) {
            throw new DomainException('Money cannot be negative.');
        }
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new DomainException('Currency must be an ISO 4217 code.');
        }
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->minor - $other->minor, $this->currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new DomainException('Currency mismatch.');
        }
    }
}
