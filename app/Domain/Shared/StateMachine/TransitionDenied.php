<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine;

use DomainException;

/** Thrown when a transition is rejected; `stage` names the failing pipeline step. */
final class TransitionDenied extends DomainException
{
    public const INVALID = 'INVALID_TRANSITION';
    public const ACTOR = 'ACTOR_NOT_ALLOWED';
    public const PERMISSION = 'PERMISSION_DENIED';
    public const AUTHORITY = 'AUTHORITY_DENIED';
    public const GUARD = 'GUARD_FAILED';

    public function __construct(
        public readonly string $stage,
        string $message,
        public readonly string $machine,
        public readonly string $fromState,
        public readonly string $event,
        public readonly ?string $failurePath = null,
    ) {
        parent::__construct($message);
    }
}
