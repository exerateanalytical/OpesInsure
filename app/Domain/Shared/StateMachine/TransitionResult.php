<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine;

final class TransitionResult
{
    public function __construct(
        public readonly string $machine,
        public readonly int $machineVersion,
        public readonly TransitionDefinition $transition,
        public readonly string $from,
        public readonly string $to,
        public readonly TransitionContext $context,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
