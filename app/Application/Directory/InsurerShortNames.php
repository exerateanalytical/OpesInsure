<?php

declare(strict_types=1);

namespace App\Application\Directory;

use App\Models\Carrier;

/**
 * Owner-approved short brand names and display order for the 29 licensed
 * Cameroon insurers (owner, 2026-09-29). Cards, lists, offer comparisons and
 * insurer chips show the short name; only the insurer details page shows the
 * full legal name. Keyed on carriers.insurer_code, which the official register
 * seeder assigns by regulator sequence (never by guessing a name).
 *
 * Stored on carriers.brand_short_name / carriers.display_order (not the
 * register short_name, which is part of the regulatory name history).
 */
final class InsurerShortNames
{
    /** insurer_code => [short name, display order] */
    public const MAP = [
        'ACTIVA' => ['ACTIVA', 1], 'AFG' => ['AFG', 2], 'AFRI' => ['AFRI', 3], 'AREA' => ['AREA', 4], 'AGC' => ['AGC', 5],
        'AXA' => ['AXA', 6], 'BELIFE_GENERAL' => ['Belife General', 7], 'CHANAS' => ['Chanas', 8], 'CPA' => ['CPA', 9],
        'GMC' => ['GMC', 10], 'LD' => ['LD', 11], 'NSIA_IARD' => ['NSIA', 12], 'PROASSUR' => ['Pro Assur', 13],
        'ROYAL_ONYX' => ['Royal Onyx', 14], 'SAAR' => ['SAAR', 15], 'SANLAM_ALLIANZ_IARD' => ['SanlamAllianz', 16],
        'SUNU_IARD' => ['SUNU', 17], 'ZENITHE' => ['Zenithe', 18],
        'ACAM_VIE' => ['ACAM Vie', 19], 'ACTIVA_VIE' => ['ACTIVA Vie', 20], 'AFRILIFE' => ['AFRILIFE', 21], 'BELIFE' => ['Belife', 22],
        'CHANAS_VIE' => ['Chanas Vie', 23], 'NSIA_VIE' => ['NSIA Vie', 24], 'SAAR_VIE' => ['SAAR Vie', 25],
        'SANLAM_ALLIANZ_VIE' => ['SanlamAllianz Vie', 26], 'SONAM_VIE' => ['SONAM Vie', 27], 'SUNU_VIE' => ['SUNU Vie', 28], 'WAFA_VIE' => ['Wafa Vie', 29],
    ];

    /** @return array{brand_short_name: string, display_order: int}|null */
    public static function attributesFor(?string $insurerCode): ?array
    {
        $m = $insurerCode !== null ? (self::MAP[$insurerCode] ?? null) : null;

        return $m ? ['brand_short_name' => $m[0], 'display_order' => $m[1]] : null;
    }

    /** Short display name for cards and chips: brand short name, else trade name, else party / legal name. */
    public static function shortOf(?Carrier $c): ?string
    {
        if (! $c) {
            return null;
        }

        return $c->brand_short_name ?: ($c->trade_name ?: ($c->party?->display_name ?: $c->legal_name));
    }

    /**
     * Additive keys for any payload that carries an insurer.
     *
     * @return array{carrier_short_name: ?string, carrier_display_order: ?int}
     */
    public static function payload(?Carrier $c): array
    {
        return ['carrier_short_name' => self::shortOf($c), 'carrier_display_order' => $c?->display_order];
    }

    /** Apply the map to every carrier with a known insurer_code. Idempotent. Returns rows updated. */
    public static function sync(): int
    {
        $n = 0;
        foreach (self::MAP as $code => [$short, $order]) {
            $n += \Illuminate\Support\Facades\DB::table('carriers')->where('insurer_code', $code)
                ->where(fn ($q) => $q->whereNull('brand_short_name')->orWhere('brand_short_name', '!=', $short)->orWhereNull('display_order')->orWhere('display_order', '!=', $order))
                ->update(['brand_short_name' => $short, 'display_order' => $order]);
        }

        return $n;
    }
}
