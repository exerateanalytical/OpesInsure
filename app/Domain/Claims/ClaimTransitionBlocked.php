<?php

declare(strict_types=1);

namespace App\Domain\Claims;

use Illuminate\Validation\ValidationException;

/** A tagged ClaimTransitionGuard blocked the transition (HTTP 422, errors.status = [reason code]). */
final class ClaimTransitionBlocked extends ValidationException
{
    public string $reasonCode = '';

    public string $event = '';

    public string $guard = '';

    public static function by(string $guard, string $event, string $reasonCode): self
    {
        $e = self::withMessages(['status' => [$reasonCode]]);
        $e->reasonCode = $reasonCode;
        $e->event = $event;
        $e->guard = $guard;

        return $e;
    }
}
