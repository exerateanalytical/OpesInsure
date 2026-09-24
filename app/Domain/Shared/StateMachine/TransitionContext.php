<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine;

/** Everything a guard, permission check or authority hook may inspect for one transition attempt. */
final class TransitionContext
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly string $currentState,
        public readonly mixed $actor = null,
        public readonly ?string $actorRole = null,
        public readonly array $payload = [],
        public readonly ?string $reason = null,
        public readonly mixed $subject = null,
    ) {}

    public function actorId(): ?string
    {
        if ($this->actor === null) {
            return null;
        }
        if (is_object($this->actor) && method_exists($this->actor, 'getAuthIdentifier')) {
            return (string) $this->actor->getAuthIdentifier();
        }

        return is_scalar($this->actor) ? (string) $this->actor : null;
    }
}
