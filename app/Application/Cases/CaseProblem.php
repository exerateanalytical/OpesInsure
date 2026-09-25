<?php

declare(strict_types=1);

namespace App\Application\Cases;

use App\Domain\Shared\StateMachine\TransitionDenied;
use App\Interfaces\Http\Errors\ApiProblemException;

/** Case-engine problems rendered through the Batch 1 error envelope (REQ-API-003). */
final class CaseProblem
{
    public static function fromDenied(TransitionDenied $e): ApiProblemException
    {
        $status = match ($e->stage) {
            TransitionDenied::PERMISSION, TransitionDenied::ACTOR, TransitionDenied::AUTHORITY => 403,
            TransitionDenied::GUARD => 422,
            default => 409,
        };

        return new ApiProblemException($e->stage, $status, $e->getMessage(), [], array_filter([
            'machine' => $e->machine, 'from_state' => $e->fromState, 'event' => $e->event, 'failure_path' => $e->failurePath,
        ]));
    }

    public static function make(string $code, int $status, string $message, array $extra = []): ApiProblemException
    {
        return new ApiProblemException($code, $status, $message, [], $extra);
    }
}
