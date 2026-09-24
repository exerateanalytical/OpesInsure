<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine;

use App\Domain\Shared\StateMachine\Contracts\AuthorityHook;
use App\Domain\Shared\StateMachine\Contracts\Guard;
use App\Domain\Shared\StateMachine\Contracts\PermissionChecker;
use App\Domain\Shared\StateMachine\Contracts\TransitionEventPublisher;
use App\Domain\Shared\StateMachine\Contracts\TransitionHistoryRecorder;
use App\Domain\Shared\StateMachine\Recorders\InMemoryTransitionHistoryRecorder;

/**
 * REQ-WFL-001 generic transition pipeline:
 *   resolve transition -> actor role -> permission -> authority hook -> guards -> history -> domain event.
 *
 * The engine never persists the subject status itself: the owning service does that inside its own
 * DB transaction (REQ-ARC-002), so history + outbox writes commit atomically with the status change.
 */
final class StateMachineEngine
{
    /** @var array<string,Guard> */
    private array $guards = [];

    /** @var array<string,AuthorityHook> */
    private array $authorities = [];

    private TransitionHistoryRecorder $history;

    public function __construct(
        ?TransitionHistoryRecorder $history = null,
        private readonly ?TransitionEventPublisher $events = null,
        private readonly ?PermissionChecker $permissions = null,
    ) {
        $this->history = $history ?? new InMemoryTransitionHistoryRecorder();
    }

    public function registerGuard(string $key, Guard|\Closure $guard): self
    {
        $this->guards[$key] = $guard instanceof Guard ? $guard : new class($guard) implements Guard {
            public function __construct(private readonly \Closure $fn) {}

            public function check(TransitionDefinition $transition, TransitionContext $context): GuardResult
            {
                $r = ($this->fn)($transition, $context);

                return $r instanceof GuardResult ? $r : ($r ? GuardResult::pass() : GuardResult::fail('Guard rejected the transition.'));
            }
        };

        return $this;
    }

    public function registerAuthority(string $key, AuthorityHook $hook): self
    {
        $this->authorities[$key] = $hook;

        return $this;
    }

    /** Non-throwing check. */
    public function can(StateMachineDefinition $machine, string $event, TransitionContext $context): bool
    {
        try {
            $this->evaluate($machine, $event, $context);

            return true;
        } catch (TransitionDenied) {
            return false;
        }
    }

    /** @return list<TransitionDefinition> transitions currently available to this actor */
    public function available(StateMachineDefinition $machine, TransitionContext $context): array
    {
        return array_values(array_filter(
            $machine->transitionsFrom($context->currentState),
            fn (TransitionDefinition $t) => $this->can($machine, $t->event, $context),
        ));
    }

    /** Validates, records history and publishes the domain event. Throws TransitionDenied. */
    public function apply(StateMachineDefinition $machine, string $event, TransitionContext $context): TransitionResult
    {
        $t = $this->evaluate($machine, $event, $context);
        $result = new TransitionResult($machine->name, $machine->version, $t, $context->currentState, $t->to, $context, new \DateTimeImmutable());
        if ($t->audit) {
            $this->history->record($result);
        }
        $this->events?->publish($result);

        return $result;
    }

    /** Status-target variant, for APIs that post the destination status rather than an event. */
    public function applyTo(StateMachineDefinition $machine, string $to, TransitionContext $context): TransitionResult
    {
        $t = $machine->transitionTo($context->currentState, $to);
        if ($t === null) {
            throw new TransitionDenied(TransitionDenied::INVALID, "Invalid {$machine->name} transition from {$context->currentState} to {$to}.", $machine->name, $context->currentState, '->'.$to);
        }

        return $this->apply($machine, $t->event, $context);
    }

    private function evaluate(StateMachineDefinition $m, string $event, TransitionContext $c): TransitionDefinition
    {
        $deny = static fn (string $stage, string $msg, ?TransitionDefinition $t = null) => new TransitionDenied($stage, $msg, $m->name, $c->currentState, $event, $t?->failurePath);

        $t = $m->transitionFor($c->currentState, $event);
        if ($t === null) {
            throw $deny(TransitionDenied::INVALID, "Invalid {$m->name} transition: event {$event} is not allowed from {$c->currentState}.");
        }
        if ($t->actors !== [] && ! in_array($c->actorRole, $t->actors, true)) {
            throw $deny(TransitionDenied::ACTOR, "Actor role ".($c->actorRole ?? 'none')." may not perform {$event}.", $t);
        }
        if ($t->permission !== null) {
            if ($this->permissions === null || ! $this->permissions->allows($t->permission, $c)) {
                throw $deny(TransitionDenied::PERMISSION, "Permission {$t->permission} is required for {$event}.", $t);
            }
        }
        if ($t->authority !== null) {
            $hook = $this->authorities[$t->authority] ?? null;
            $r = $hook?->authorize($t->authority, $t, $c) ?? GuardResult::fail("No authority hook registered for {$t->authority}.");
            if (! $r->passed) {
                throw $deny(TransitionDenied::AUTHORITY, (string) $r->reason, $t);
            }
        }
        foreach ($t->guards as $key) {
            $g = $this->guards[$key] ?? null;
            $r = $g?->check($t, $c) ?? GuardResult::fail("Guard {$key} is not registered.");
            if (! $r->passed) {
                throw $deny(TransitionDenied::GUARD, (string) $r->reason, $t);
            }
        }

        return $t;
    }
}
