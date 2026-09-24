<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine;

use InvalidArgumentException;

/**
 * REQ-WFL-001: a validated, versioned machine definition in Blueprint Part II format.
 *
 * Array format accepted by fromArray():
 * [
 *   'name' => 'claim', 'version' => 1, 'subject_type' => 'claim',
 *   'states' => ['DRAFT' => ['initial' => true], 'CLOSED' => ['terminal' => true], ...],
 *   'transitions' => [
 *     ['event' => 'submit', 'from' => ['DRAFT'], 'to' => 'SUBMITTED', 'actors' => ['customer'],
 *      'guards' => ['evidence_complete'], 'permission' => 'claims.submit', 'authority' => null,
 *      'side_effects' => [...], 'audit' => true, 'notification' => 'claim.submitted',
 *      'domain_event' => 'claim.fnol.submitted', 'failure_path' => 'stay DRAFT; show missing evidence'],
 *   ],
 * ]
 */
final class StateMachineDefinition
{
    /** @var array<string,StateDefinition> */
    private array $states = [];

    /** @var list<TransitionDefinition> */
    private array $transitions;

    /**
     * @param list<StateDefinition>      $states
     * @param list<TransitionDefinition> $transitions
     */
    public function __construct(
        public readonly string $name,
        array $states,
        array $transitions,
        public readonly int $version = 1,
        public readonly ?string $subjectType = null,
    ) {
        foreach ($states as $s) {
            if (isset($this->states[$s->code])) {
                throw new InvalidArgumentException("Machine {$name}: duplicate state {$s->code}.");
            }
            $this->states[$s->code] = $s;
        }
        $this->transitions = array_values($transitions);
        $this->validate();
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d): self
    {
        $states = [];
        foreach ($d['states'] ?? [] as $code => $meta) {
            if (is_int($code)) {
                [$code, $meta] = [(string) $meta, []];
            }
            $states[] = new StateDefinition((string) $code, (bool) ($meta['initial'] ?? false), (bool) ($meta['terminal'] ?? false), $meta['label'] ?? null);
        }
        $transitions = array_map(static fn (array $t) => new TransitionDefinition(
            event: (string) $t['event'],
            from: array_values((array) $t['from']),
            to: (string) $t['to'],
            actors: array_values($t['actors'] ?? []),
            guards: array_values($t['guards'] ?? []),
            permission: $t['permission'] ?? null,
            authority: $t['authority'] ?? null,
            sideEffects: array_values($t['side_effects'] ?? []),
            audit: (bool) ($t['audit'] ?? true),
            notification: $t['notification'] ?? null,
            domainEvent: $t['domain_event'] ?? null,
            failurePath: $t['failure_path'] ?? null,
        ), $d['transitions'] ?? []);

        return new self((string) $d['name'], $states, $transitions, (int) ($d['version'] ?? 1), $d['subject_type'] ?? null);
    }

    private function validate(): void
    {
        if ($this->states === []) {
            throw new InvalidArgumentException("Machine {$this->name}: no states.");
        }
        $initial = array_filter($this->states, fn (StateDefinition $s) => $s->initial);
        if (count($initial) > 1) {
            throw new InvalidArgumentException("Machine {$this->name}: more than one initial state.");
        }
        $seen = [];
        foreach ($this->transitions as $t) {
            foreach ([...$t->from, $t->to] as $code) {
                if (! isset($this->states[$code])) {
                    throw new InvalidArgumentException("Machine {$this->name}: transition {$t->event} references unknown state {$code}.");
                }
            }
            foreach ($t->from as $from) {
                if ($this->states[$from]->terminal) {
                    throw new InvalidArgumentException("Machine {$this->name}: transition {$t->event} leaves terminal state {$from}.");
                }
                $key = $from.'|'.$t->event;
                if (isset($seen[$key])) {
                    throw new InvalidArgumentException("Machine {$this->name}: event {$t->event} is ambiguous from {$from}.");
                }
                $seen[$key] = true;
            }
        }
    }

    /** @return array<string,StateDefinition> */
    public function states(): array
    {
        return $this->states;
    }

    public function hasState(string $code): bool
    {
        return isset($this->states[$code]);
    }

    public function initialState(): ?string
    {
        foreach ($this->states as $s) {
            if ($s->initial) {
                return $s->code;
            }
        }

        return null;
    }

    /** @return list<TransitionDefinition> */
    public function transitions(): array
    {
        return $this->transitions;
    }

    public function transitionFor(string $from, string $event): ?TransitionDefinition
    {
        foreach ($this->transitions as $t) {
            if ($t->event === $event && $t->allowsFrom($from)) {
                return $t;
            }
        }

        return null;
    }

    /** First transition that moves $from to $to (used by status-target APIs such as claims/{id}/transitions). */
    public function transitionTo(string $from, string $to): ?TransitionDefinition
    {
        foreach ($this->transitions as $t) {
            if ($t->to === $to && $t->allowsFrom($from)) {
                return $t;
            }
        }

        return null;
    }

    /** @return list<TransitionDefinition> */
    public function transitionsFrom(string $from): array
    {
        return array_values(array_filter($this->transitions, fn (TransitionDefinition $t) => $t->allowsFrom($from)));
    }

    /** @return array<string,list<string>> from => [to...] */
    public function adjacency(): array
    {
        $out = [];
        foreach ($this->transitions as $t) {
            foreach ($t->from as $f) {
                $out[$f] ??= [];
                if (! in_array($t->to, $out[$f], true)) {
                    $out[$f][] = $t->to;
                }
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $states = [];
        foreach ($this->states as $s) {
            $states[$s->code] = ['initial' => $s->initial, 'terminal' => $s->terminal, 'label' => $s->label];
        }

        return [
            'name' => $this->name, 'version' => $this->version, 'subject_type' => $this->subjectType,
            'states' => $states,
            'transitions' => array_map(fn (TransitionDefinition $t) => $t->toArray(), $this->transitions),
        ];
    }
}
