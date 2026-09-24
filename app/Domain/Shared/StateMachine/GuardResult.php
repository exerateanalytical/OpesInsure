<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine;

final class GuardResult
{
    private function __construct(public readonly bool $passed, public readonly ?string $reason) {}

    public static function pass(): self
    {
        return new self(true, null);
    }

    public static function fail(string $reason): self
    {
        return new self(false, $reason);
    }
}
