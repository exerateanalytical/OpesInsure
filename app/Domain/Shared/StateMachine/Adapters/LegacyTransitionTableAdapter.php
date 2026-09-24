<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine\Adapters;

use App\Domain\Shared\StateMachine\StateDefinition;
use App\Domain\Shared\StateMachine\StateMachineDefinition;
use App\Domain\Shared\StateMachine\TransitionDefinition;
use InvalidArgumentException;
use ReflectionClassConstant;

/**
 * Bridges the existing `FROM => [TO...]` constant tables onto the generic engine WITHOUT copying them,
 * so the legacy class stays the single source until its domain wave migrates (REQ-WFL-001, REQ-DUP-006).
 *
 * Each (from,to) edge becomes one transition whose event is "to_<to lowercase>".
 */
final class LegacyTransitionTableAdapter
{
    /** @param array<string,list<string>> $table */
    public static function fromTable(string $machine, array $table, ?string $initial = null, int $version = 1, ?string $subjectType = null): StateMachineDefinition
    {
        $codes = [];
        foreach ($table as $from => $tos) {
            $codes[$from] = true;
            foreach ($tos as $to) {
                $codes[$to] = true;
            }
        }
        $states = [];
        foreach (array_keys($codes) as $code) {
            $code = (string) $code;
            $states[] = new StateDefinition($code, $code === $initial, ! isset($table[$code]) || $table[$code] === []);
        }
        $byTo = [];
        foreach ($table as $from => $tos) {
            foreach ($tos as $to) {
                $byTo[$to][] = (string) $from;
            }
        }
        $transitions = [];
        foreach ($byTo as $to => $froms) {
            $transitions[] = new TransitionDefinition(event: self::eventFor((string) $to), from: $froms, to: (string) $to);
        }

        return new StateMachineDefinition($machine, $states, $transitions, $version, $subjectType);
    }

    /** Reads a private/public class constant table via reflection (no copy of the data). */
    public static function fromClassConstant(string $class, string $constant, string $machine, ?string $initial = null, ?string $subjectType = null): StateMachineDefinition
    {
        $table = (new ReflectionClassConstant($class, $constant))->getValue();
        if (! is_array($table)) {
            throw new InvalidArgumentException("{$class}::{$constant} is not a transition table.");
        }

        return self::fromTable($machine, $table, $initial, 1, $subjectType);
    }

    public static function eventFor(string $to): string
    {
        return 'to_'.strtolower($to);
    }
}
