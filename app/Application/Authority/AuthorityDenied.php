<?php

declare(strict_types=1);

namespace App\Application\Authority;

use Illuminate\Validation\ValidationException;

/**
 * Thrown inside a caller's transaction on a DENIED outcome; the caller catches it after the rollback, persists the
 * denial via AuthorityService::record() and rethrows the validation error.
 */
final class AuthorityDenied extends \RuntimeException
{
    public function __construct(public readonly AuthorityOutcome $outcome, public readonly ValidationException $error)
    {
        parent::__construct($outcome->reason);
    }
}
