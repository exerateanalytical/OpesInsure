<?php

declare(strict_types=1);

namespace App\Domain\Rating;

use DomainException;

/**
 * REQ-RAT-001: deterministic integer money. Every amount is an int in minor units (*_minor);
 * rates are integer basis points (1/10 000) or parts per million (1/1 000 000). No float ever
 * touches an amount, so the same inputs give the same minor units on every machine.
 */
final class IntegerMoney
{
    /** Integer division rounded half away from zero (the same rule as PHP round() on the legacy path). */
    public static function divRound(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new DomainException('Rating denominator must be positive.');
        }
        $q = intdiv($numerator, $denominator);
        $r = $numerator % $denominator;
        if (abs($r) * 2 >= $denominator) {
            $q += $numerator < 0 ? -1 : 1;
        }

        return $q;
    }

    public static function bp(int $amount, int $basisPoints): int
    {
        return self::divRound(self::mul($amount, $basisPoints), 10000);
    }

    public static function ppm(int $amount, int $partsPerMillion): int
    {
        return self::divRound(self::mul($amount, $partsPerMillion), 1000000);
    }

    public static function mul(int $a, int $b): int
    {
        $r = $a * $b;
        if (! is_int($r)) {
            throw new DomainException('Rating arithmetic overflow.');
        }

        return $r;
    }

    /** Round $amount to a multiple of $unit (HALF_UP, UP or DOWN). */
    public static function toUnit(int $amount, int $unit, string $mode = 'HALF_UP'): int
    {
        if ($unit <= 1) {
            return $amount;
        }
        $down = intdiv($amount, $unit) * $unit;
        $rest = $amount - $down;

        return match ($mode) {
            'DOWN' => $down,
            'UP' => $rest === 0 ? $amount : $down + $unit,
            'HALF_UP' => $rest * 2 >= $unit ? $down + $unit : $down,
            default => throw new DomainException('Unsupported rounding mode.'),
        };
    }

    /** A fact that must be a whole number (money in minor units, counts). Floats with a fraction are refused. */
    public static function wholeFact(array $facts, string $key): int
    {
        $v = data_get($facts, $key);
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v) && floor($v) === $v && abs($v) < PHP_INT_MAX) {
            return (int) $v;
        }
        if (is_string($v) && preg_match('/^-?\d{1,18}$/', $v)) {
            return (int) $v;
        }
        throw new DomainException("Rating fact {$key} must be a whole number.");
    }

    /**
     * Largest-remainder split of $amount by basis-point shares that sum to 10 000; the parts always
     * add back to exactly $amount (REQ-RAT-005).
     *
     * @param  array<string,int>  $shares
     * @return array<string,int>
     */
    public static function allocate(int $amount, array $shares): array
    {
        if (array_sum($shares) !== 10000) {
            throw new DomainException('Allocation shares must sum to 10000 basis points.');
        }
        $parts = [];
        $remainders = [];
        foreach ($shares as $k => $s) {
            $parts[$k] = intdiv(self::mul($amount, $s), 10000);
            $remainders[$k] = self::mul($amount, $s) % 10000;
        }
        $left = $amount - array_sum($parts);
        uksort($remainders, fn ($a, $b) => [$remainders[$b], (string) $a] <=> [$remainders[$a], (string) $b]);
        foreach (array_keys($remainders) as $k) {
            if ($left <= 0) {
                break;
            }
            $parts[$k]++;
            $left--;
        }

        return $parts;
    }
}
