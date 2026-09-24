<?php

declare(strict_types=1);

namespace App\Application\MasterData;

/** Accent-, case- and punctuation-insensitive keys for search and duplicate prevention. */
final class MasterDataNormalizer
{
    public static function normalize(?string $text): string
    {
        $text = (string) $text;
        $ascii = function_exists('transliterator_transliterate')
            ? (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text)
            : strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($ascii)));
    }

    /** @param array<int, string|null> $parts */
    public static function searchText(array $parts): string
    {
        $norm = array_values(array_unique(array_filter(array_map(
            fn ($p) => self::normalize(str_replace('_', ' ', (string) $p)),
            $parts,
        ))));

        return ' '.implode(' | ', $norm).' ';
    }

    /** Canonical code from a free-text label: "Tôlier (garage)" → TOLIER_GARAGE. */
    public static function codeFrom(string $label): string
    {
        return substr(strtoupper(str_replace(' ', '_', self::normalize($label))), 0, 120) ?: 'VALUE';
    }
}
