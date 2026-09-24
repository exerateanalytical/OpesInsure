<?php

declare(strict_types=1);

namespace App\Domain\Shared\Clock;

use Carbon\CarbonImmutable;

/**
 * REQ-TMP-001 / ICE E1 INV-1.1: the only source of "now" for engines and
 * domain services. Bound in App\Providers\TemporalServiceProvider.
 */
interface Clock
{
    /** Current instant, in the application timezone. */
    public function now(): CarbonImmutable;

    /** Current business date (midnight) in the given timezone (default: app timezone). */
    public function today(?string $timezone = null): CarbonImmutable;
}
