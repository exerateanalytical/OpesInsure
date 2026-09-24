<?php

declare(strict_types=1);

namespace App\Domain\Shared\Clock;

use Carbon\CarbonImmutable;

final class SystemClock implements Clock
{
    public function __construct(private readonly string $timezone = 'Africa/Douala') {}

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone);
    }

    public function today(?string $timezone = null): CarbonImmutable
    {
        return $this->now()->setTimezone($timezone ?? $this->timezone)->startOfDay();
    }
}
