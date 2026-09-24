<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine;

use InvalidArgumentException;

/** Named machine definitions. Domains register their definition in their own wave. */
final class StateMachineRegistry
{
    /** @var array<string,StateMachineDefinition|\Closure():StateMachineDefinition> */
    private array $machines = [];

    public function register(string $name, StateMachineDefinition|\Closure $definition): void
    {
        $this->machines[$name] = $definition;
    }

    public function has(string $name): bool
    {
        return isset($this->machines[$name]);
    }

    public function get(string $name): StateMachineDefinition
    {
        $d = $this->machines[$name] ?? throw new InvalidArgumentException("Unknown state machine {$name}.");
        if ($d instanceof \Closure) {
            $d = $this->machines[$name] = $d();
        }

        return $d;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->machines);
    }
}
