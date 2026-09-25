<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

/** Display helper for integer minor units (XAF has no decimals in practice; stored ×100 like the rest of the app). */
final class Money
{
    public static function format(?int $minor, ?string $currency): string
    {
        if ($minor === null) {
            return '—';
        }

        return number_format($minor / 100, 0, '.', ' ').' '.($currency ?: 'XAF');
    }
}
