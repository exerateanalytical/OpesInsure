<?php

declare(strict_types=1);

namespace App\Application\Shared;

/**
 * Amount in words for receipts (canonical spec DOC-190 "amount in words where configured"), French and English,
 * pure PHP (no intl dependency). XAF has no subunit in use: the amount is printed in whole francs.
 */
final class AmountInWords
{
    private const FR_UNITS = ['zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];

    private const FR_TENS = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante'];

    private const EN_UNITS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];

    private const EN_TENS = [2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty', 6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety'];

    /** "cent mille francs CFA / one hundred thousand CFA francs" from a minor-unit amount. */
    public static function bilingual(int $amountMinor, string $currency = 'XAF'): string
    {
        $n = intdiv(abs($amountMinor), 100);
        [$fr, $en] = strtoupper($currency) === 'XAF' ? ['francs CFA', 'CFA francs'] : [$currency, $currency];

        return self::fr($n).' '.$fr.' / '.self::en($n).' '.$en;
    }

    public static function fr(int $n): string
    {
        if ($n === 0) {
            return 'zéro';
        }
        $out = [];
        foreach ([[1_000_000_000, 'milliard'], [1_000_000, 'million']] as [$scale, $word]) {
            if ($n >= $scale) {
                $q = intdiv($n, $scale);
                $out[] = self::frBelowThousand($q, true).' '.$word.($q > 1 ? 's' : '');
                $n %= $scale;
            }
        }
        if ($n >= 1000) {
            $q = intdiv($n, 1000);
            $out[] = $q === 1 ? 'mille' : self::frBelowThousand($q, false).' mille';
            $n %= 1000;
        }
        if ($n > 0) {
            $out[] = self::frBelowThousand($n, true);
        }

        return implode(' ', $out);
    }

    public static function en(int $n): string
    {
        if ($n === 0) {
            return 'zero';
        }
        $out = [];
        foreach ([[1_000_000_000, 'billion'], [1_000_000, 'million'], [1000, 'thousand']] as [$scale, $word]) {
            if ($n >= $scale) {
                $out[] = self::enBelowThousand(intdiv($n, $scale)).' '.$word;
                $n %= $scale;
            }
        }
        if ($n > 0) {
            $out[] = self::enBelowThousand($n);
        }

        return implode(' ', $out);
    }

    /** $final: "cents"/"quatre-vingts" take their plural s only at the end of the number. */
    private static function frBelowThousand(int $n, bool $final): string
    {
        $h = intdiv($n, 100);
        $r = $n % 100;
        $parts = [];
        if ($h > 0) {
            $parts[] = $h === 1 ? 'cent' : self::FR_UNITS[$h].' cent'.($r === 0 && $final ? 's' : '');
        }
        if ($r > 0) {
            $parts[] = self::frBelowHundred($r, $final);
        }

        return implode(' ', $parts);
    }

    private static function frBelowHundred(int $n, bool $final): string
    {
        if ($n < 20) {
            return self::FR_UNITS[$n];
        }
        $t = intdiv($n, 10);
        $u = $n % 10;
        if ($t === 7 || $t === 9) { // soixante-dix..., quatre-vingt-dix...
            $base = $t === 7 ? 'soixante' : 'quatre-vingt';
            $rest = 10 + $u;

            return $base.($t === 7 && $u === 1 ? ' et ' : '-').self::FR_UNITS[$rest];
        }
        if ($t === 8) {
            return $u === 0 ? 'quatre-vingt'.($final ? 's' : '') : 'quatre-vingt-'.self::FR_UNITS[$u];
        }
        if ($u === 0) {
            return self::FR_TENS[$t];
        }

        return self::FR_TENS[$t].($u === 1 ? ' et un' : '-'.self::FR_UNITS[$u]);
    }

    private static function enBelowThousand(int $n): string
    {
        $h = intdiv($n, 100);
        $r = $n % 100;
        $parts = [];
        if ($h > 0) {
            $parts[] = self::EN_UNITS[$h].' hundred';
        }
        if ($r > 0) {
            $parts[] = $r < 20 ? self::EN_UNITS[$r] : self::EN_TENS[intdiv($r, 10)].($r % 10 ? '-'.self::EN_UNITS[$r % 10] : '');
        }

        return implode(' ', $parts);
    }
}
