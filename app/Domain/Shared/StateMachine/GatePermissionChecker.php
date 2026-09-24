<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine;

use App\Domain\Shared\StateMachine\Contracts\PermissionChecker;

/** Default checker: actor->can($permission[, $subject]). No actor means denied. */
final class GatePermissionChecker implements PermissionChecker
{
    public function allows(string $permission, TransitionContext $context): bool
    {
        $actor = $context->actor;
        if (! is_object($actor) || ! method_exists($actor, 'can')) {
            return false;
        }

        return (bool) ($context->subject !== null ? $actor->can($permission, $context->subject) : $actor->can($permission));
    }
}
