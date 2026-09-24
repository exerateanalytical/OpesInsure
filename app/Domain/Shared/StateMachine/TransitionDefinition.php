<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine;

/**
 * REQ-WFL-001: one row of the Blueprint Part II table
 * (state, event, from, to, actor, guard, authority, side effect, audit, notification, failure path).
 */
final class TransitionDefinition
{
    /**
     * @param list<string> $from
     * @param list<string> $actors       actor roles allowed; empty = any actor (incl. system)
     * @param list<string> $guards       guard keys resolved through the engine guard map
     * @param list<string> $sideEffects  declared side effects (executed by the owning service, not the engine)
     */
    public function __construct(
        public readonly string $event,
        public readonly array $from,
        public readonly string $to,
        public readonly array $actors = [],
        public readonly array $guards = [],
        public readonly ?string $permission = null,
        public readonly ?string $authority = null,
        public readonly array $sideEffects = [],
        public readonly bool $audit = true,
        public readonly ?string $notification = null,
        public readonly ?string $domainEvent = null,
        public readonly ?string $failurePath = null,
    ) {}

    public function allowsFrom(string $state): bool
    {
        return in_array($state, $this->from, true);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'event' => $this->event, 'from' => $this->from, 'to' => $this->to, 'actors' => $this->actors,
            'guards' => $this->guards, 'permission' => $this->permission, 'authority' => $this->authority,
            'side_effects' => $this->sideEffects, 'audit' => $this->audit, 'notification' => $this->notification,
            'domain_event' => $this->domainEvent, 'failure_path' => $this->failurePath,
        ];
    }
}
