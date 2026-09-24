<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine;

/** REQ-WFL-001: one state of a machine. */
final class StateDefinition
{
    public function __construct(
        public readonly string $code,
        public readonly bool $initial = false,
        public readonly bool $terminal = false,
        public readonly ?string $label = null,
    ) {}
}
