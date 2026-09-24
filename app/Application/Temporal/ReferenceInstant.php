<?php

declare(strict_types=1);

namespace App\Application\Temporal;

use Carbon\CarbonImmutable;

/** REQ-TMP-001: the valid-time instant a resolution uses, and why. */
final class ReferenceInstant
{
    public function __construct(
        public readonly CarbonImmutable $referenceAt,
        public readonly string $anchor = 'EXPLICIT',
        public readonly ?int $ruleVersion = null,
        public readonly string $timezone = 'Africa/Douala',
    ) {}

    public static function at(\DateTimeInterface|string $at, string $timezone = 'Africa/Douala'): self
    {
        return new self(CarbonImmutable::parse($at, $timezone), 'EXPLICIT', null, $timezone);
    }

    /** Business date of the reference instant in its timezone (DAY-granularity artifacts). */
    public function businessDate(): string
    {
        return $this->referenceAt->setTimezone($this->timezone)->toDateString();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['reference_at' => $this->referenceAt->toIso8601String(), 'anchor' => $this->anchor, 'rule_version' => $this->ruleVersion, 'timezone' => $this->timezone];
    }
}
