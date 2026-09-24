<?php

declare(strict_types=1);

namespace App\Domain\Shared\Clock;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Deterministic clock for tests and replay (ICE E1 §1.4). */
final class FrozenClock implements Clock
{
    private CarbonImmutable $instant;

    public function __construct(DateTimeInterface|string $instant, private readonly string $timezone = 'Africa/Douala')
    {
        $this->instant = CarbonImmutable::parse($instant)->setTimezone($timezone);
    }

    public function now(): CarbonImmutable
    {
        return $this->instant;
    }

    public function today(?string $timezone = null): CarbonImmutable
    {
        return $this->instant->setTimezone($timezone ?? $this->timezone)->startOfDay();
    }

    public function travelTo(DateTimeInterface|string $instant): void
    {
        $this->instant = CarbonImmutable::parse($instant)->setTimezone($this->timezone);
    }

    public function advance(string $interval): void
    {
        $this->instant = $this->instant->add($interval);
    }
}
