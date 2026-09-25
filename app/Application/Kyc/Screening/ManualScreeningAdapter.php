<?php

declare(strict_types=1);

namespace App\Application\Kyc\Screening;

use App\Models\Party;

/**
 * MANUAL_AUDITED mode (owner decision 27): never decides; every check stays PENDING until a reviewer records what
 * they consulted (audited). Legacy rows carry provider "MANUAL" — see ScreeningMode::normalize().
 */
final class ManualScreeningAdapter implements ScreeningAdapter
{
    public function code(): string
    {
        return ScreeningMode::MANUAL_AUDITED;
    }

    public function screen(Party $party, string $checkType): ?array
    {
        return null;
    }
}
