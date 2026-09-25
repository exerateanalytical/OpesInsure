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

    /**
     * UI display (canonical handoff "Insurance and financial UI rules"): XAF is
     * shown as FCFA, grouping follows the locale (fr: narrow no-break space,
     * en: comma) and a no-break space keeps amount and FCFA together.
     * format() above remains the stable serialised value.
     */
    public static function display(?int $minor, ?string $currency = 'XAF', ?string $locale = null): string
    {
        if ($minor === null) {
            return '—';
        }
        $locale ??= app()->getLocale();
        $code = strtoupper($currency ?: 'XAF');
        $amount = number_format($minor / 100, 0, '.', $locale === 'fr' ? "\u{202F}" : ',');

        return $amount."\u{00A0}".($code === 'XAF' ? 'FCFA' : $code);
    }
}
