<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine\Contracts;

use App\Domain\Shared\StateMachine\TransitionContext;

interface PermissionChecker
{
    public function allows(string $permission, TransitionContext $context): bool;
}
