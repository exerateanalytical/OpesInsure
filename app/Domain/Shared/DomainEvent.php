<?php

declare(strict_types=1);

namespace App\Domain\Shared;

interface DomainEvent
{
    public function eventName(): string;
    public function occurredAt(): \DateTimeImmutable;
    public function payload(): array;
}
