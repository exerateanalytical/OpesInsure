<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine\Contracts;

use App\Domain\Shared\StateMachine\GuardResult;
use App\Domain\Shared\StateMachine\TransitionContext;
use App\Domain\Shared\StateMachine\TransitionDefinition;

interface Guard
{
    public function check(TransitionDefinition $transition, TransitionContext $context): GuardResult;
}
