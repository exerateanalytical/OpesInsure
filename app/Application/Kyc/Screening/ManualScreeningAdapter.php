<?php

declare(strict_types=1);

namespace App\Application\Kyc\Screening;

use App\Models\Party;

/** MANUAL mode: never decides; every check stays PENDING until a reviewer records what they consulted. */
final class ManualScreeningAdapter implements ScreeningAdapter
{
    public function code(): string
    {
        return 'MANUAL';
    }

    public function screen(Party $party, string $checkType): ?array
    {
        return null;
    }
}
