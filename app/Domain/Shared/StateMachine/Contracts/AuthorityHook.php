<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine\Contracts;

use App\Domain\Shared\StateMachine\GuardResult;
use App\Domain\Shared\StateMachine\TransitionContext;
use App\Domain\Shared\StateMachine\TransitionDefinition;

/**
 * Delegated-authority hook (approval limits, maker-checker, carrier authority).
 * Invoked only for transitions whose definition names an `authority` key.
 */
interface AuthorityHook
{
    public function authorize(string $authorityKey, TransitionDefinition $transition, TransitionContext $context): GuardResult;
}
