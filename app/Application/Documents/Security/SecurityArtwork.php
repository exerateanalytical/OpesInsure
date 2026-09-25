<?php

declare(strict_types=1);

namespace App\Application\Documents\Security;

/**
 * Vector security graphics for issued PDFs (document_system.shared_security_artifacts; crypto spec §19).
 * Generated deterministically per document family and document number, as vector SVG (never raster):
 *  - GUIL-01 header wave / GUIL-02 corner loop / GUIL-03 certificate rosette / GUIL-04 verification backplate /
 *    GUIL-05 motor security field: interwoven variable-frequency curves, a different profile per family;
 *  - anti-copy line field (supplementary, not proof);
 *  - SEAL-02 authentication seal ring bound to the registry verification code (not an insurer corporate
 *    seal: corporate seal artwork is CONFIG_REQUIRED, never fabricated).
 * Line widths / microtext sizes are digital defaults: print validation with the production printer is
 * CONFIG_REQUIRED (§19.2-19.3).
 */
final class SecurityArtwork
{
    public const FAMILY_COLORS = [
        'POLICY' => '#0b2a4a', 'MOTOR' => '#12405e', 'HEALTH' => '#0f5b4a', 'FINANCE' => '#3d2f6b', 'CLAIMS' => '#6b2f2f', 'DEFAULT' => '#0b2a4a',
    ];

    public static function familyOf(string $code, ?string $category = null): string
    {
        return match (true) {
            (bool) preg_match('/MOTOR|VEHICLE|FLEET|ATTESTATION/', $code) || $category === 'C' => 'MOTOR',
            (bool) preg_match('/HEALTH|MEDICAL|MEMBER|PREAUTH|HOSPITAL/', $code) || $category === 'D' => 'HEALTH',
            (bool) preg_match('/RECEIPT|INVOICE|PREMIUM_|PAYMENT|STATEMENT|NOTE$/', $code) || $category === 'K' => 'FINANCE',
            (bool) preg_match('/CLAIM|SETTLEMENT|DISCHARGE|RECOVERY|SUBROGATION/', $code) || $category === 'J' => 'CLAIMS',
            default => 'POLICY',
        };
    }

    private static function seedFloat(string $seed, int $i): float
    {
        return hexdec(substr(hash('sha256', $seed.'|'.$i), 0, 8)) / 0xFFFFFFFF;
    }

    private static function uri(string $svg): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** GUIL-01 header wave / GUIL-04 backplate: interwoven sine curves. */
    public static function guilloche(string $seed, int $w = 760, int $h = 60, string $color = '#0b2a4a', float $opacity = 0.35, int $lines = 14): string
    {
        $paths = '';
        for ($l = 0; $l < $lines; $l++) {
            $f1 = 2 + self::seedFloat($seed, $l) * 7;
            $f2 = 5 + self::seedFloat($seed, $l + 50) * 11;
            $phase = self::seedFloat($seed, $l + 100) * 6.283;
            $amp = $h * (0.18 + 0.28 * self::seedFloat($seed, $l + 150));
            $pts = [];
            for ($x = 0; $x <= $w; $x += 4) {
                $t = $x / $w * 6.283;
                $y = $h / 2 + $amp * sin($f1 * $t + $phase) * cos($f2 * $t / 3 + $l / 2);
                $pts[] = $x.','.round($y, 1);
            }
            $paths .= '<polyline fill="none" stroke="'.$color.'" stroke-width="0.35" points="'.implode(' ', $pts).'"/>';
        }

        return self::uri('<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'"><g opacity="'.$opacity.'">'.$paths.'</g></svg>');
    }

    /** GUIL-03 certificate rosette (hypotrochoid family). */
    public static function rosette(string $seed, int $size = 220, string $color = '#0b2a4a', float $opacity = 0.18): string
    {
        $c = $size / 2;
        $paths = '';
        for ($k = 0; $k < 6; $k++) {
            $R = $c * 0.62;
            $r = $c * (0.12 + 0.2 * self::seedFloat($seed, $k));
            $d = $c * (0.2 + 0.2 * self::seedFloat($seed, $k + 9));
            $pts = [];
            for ($i = 0; $i <= 360; $i++) {
                $t = $i / 360 * 6.283 * 7;
                $x = ($R - $r) * cos($t) + $d * cos(($R - $r) / $r * $t);
                $y = ($R - $r) * sin($t) - $d * sin(($R - $r) / $r * $t);
                $pts[] = round($c + $x, 1).','.round($c + $y, 1);
            }
            $paths .= '<polyline fill="none" stroke="'.$color.'" stroke-width="0.3" points="'.implode(' ', $pts).'"/>';
        }

        return self::uri('<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" viewBox="0 0 '.$size.' '.$size.'"><g opacity="'.$opacity.'">'.$paths.'</g></svg>');
    }

    /** Anti-copy fine-line field (moiré under resampling). Supplementary only (crypto spec §19.4). */
    public static function antiCopy(int $w = 240, int $h = 90, string $color = '#0b2a4a', float $opacity = 0.22): string
    {
        $lines = '';
        for ($x = -$h; $x < $w; $x += 3) {
            $lines .= '<line x1="'.$x.'" y1="0" x2="'.($x + $h).'" y2="'.$h.'" stroke="'.$color.'" stroke-width="0.25"/>';
        }
        for ($x = 0; $x < $w + $h; $x += 5) {
            $lines .= '<line x1="'.$x.'" y1="0" x2="'.($x - $h).'" y2="'.$h.'" stroke="'.$color.'" stroke-width="0.2"/>';
        }

        return self::uri('<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'"><g opacity="'.$opacity.'">'.$lines.'</g></svg>');
    }

    /** SEAL-02 authentication seal ring (text is overlaid in HTML by the layout). */
    public static function seal(string $seed, int $size = 110, string $color = '#0b2a4a'): string
    {
        $c = $size / 2;
        $spokes = '';
        for ($i = 0; $i < 72; $i++) {
            $a = $i / 72 * 6.283;
            $spokes .= '<line x1="'.round($c + cos($a) * $c * 0.80, 1).'" y1="'.round($c + sin($a) * $c * 0.80, 1).'" x2="'.round($c + cos($a) * $c * 0.94, 1).'" y2="'.round($c + sin($a) * $c * 0.94, 1).'" stroke="'.$color.'" stroke-width="0.6"/>';
        }
        $inner = base64_decode(substr(self::rosette($seed, (int) round($size * 0.7), $color, 0.55), strlen('data:image/svg+xml;base64,')));
        $inner = preg_replace('/^<svg[^>]*>|<\/svg>$/', '', (string) $inner);
        $offset = round($size * 0.15, 1);

        return self::uri('<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" viewBox="0 0 '.$size.' '.$size.'">'
            .'<circle cx="'.$c.'" cy="'.$c.'" r="'.round($c * 0.97, 1).'" fill="none" stroke="'.$color.'" stroke-width="1.4"/>'
            .'<circle cx="'.$c.'" cy="'.$c.'" r="'.round($c * 0.78, 1).'" fill="none" stroke="'.$color.'" stroke-width="0.8"/>'
            .$spokes.'<g transform="translate('.$offset.','.$offset.')">'.$inner.'</g></svg>');
    }

    /** Microtext strip content: repeated issuer + document number phrase. */
    public static function microtext(string $issuer, string $number, int $repeat = 18): string
    {
        $phrase = mb_strtoupper(preg_replace('/\s+/', '', $issuer) ?: 'OPESINSURE').'·'.$number.'·VERIFY·';

        return str_repeat($phrase, $repeat);
    }
}
