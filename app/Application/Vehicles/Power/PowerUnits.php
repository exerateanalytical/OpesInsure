<?php

declare(strict_types=1);

namespace App\Application\Vehicles\Power;

use Illuminate\Validation\ValidationException;

/**
 * Technical power units (Vehicle Power master technical_power_conversions). kW is canonical; mechanical hp and
 * metric PS (CV DIN) are derived from kW with the configured constants and stored distinctly. The source value and
 * unit are preserved separately. There is deliberately NO conversion to Cameroon fiscal CV (VPWR-006):
 * fiscal power comes only from an authoritative source (FiscalPowerService).
 */
final class PowerUnits
{
    public const KW_TO_MECHANICAL_HP = 1.3410220896;

    public const MECHANICAL_HP_TO_KW = 0.7456998716;

    public const KW_TO_METRIC_PS = 1.3596216173;

    public const METRIC_PS_TO_KW = 0.73549875;

    public const STORAGE_DECIMALS = 3;

    public const DISPLAY_DECIMALS = 1;

    public const CONVERSION_METHOD = 'VPWR_CONV_V1_KW_CANONICAL';

    public const SOURCE_UNITS = ['KW', 'MECHANICAL_HP', 'METRIC_PS'];

    /** Unit aliases accepted on input; "CV" alone is ambiguous (fiscal vs DIN) and refused. */
    private const ALIASES = ['KW' => 'KW', 'KILOWATT' => 'KW', 'HP' => 'MECHANICAL_HP', 'BHP' => 'MECHANICAL_HP', 'MECHANICAL_HP' => 'MECHANICAL_HP',
        'PS' => 'METRIC_PS', 'CV_DIN' => 'METRIC_PS', 'CV DIN' => 'METRIC_PS', 'METRIC_PS' => 'METRIC_PS', 'METRIC_HP' => 'METRIC_PS'];

    public static function unit(string $unit): string
    {
        return self::ALIASES[strtoupper(trim($unit))] ?? throw ValidationException::withMessages(['power_source_unit' => 'Unknown technical power unit (KW, MECHANICAL_HP or METRIC_PS). Fiscal CV is not a technical power unit.']);
    }

    public static function toKw(float $value, string $unit): float
    {
        return match (self::unit($unit)) {
            'KW' => $value,
            'MECHANICAL_HP' => $value * self::MECHANICAL_HP_TO_KW,
            'METRIC_PS' => $value * self::METRIC_PS_TO_KW,
        };
    }

    /**
     * Normalize once from the source value (never re-convert a normalized value). A source hp / PS value is kept as
     * given for its own unit; the other units are derived from kW.
     *
     * @return array{power_kw: float, power_hp: float, power_ps: float, power_source_value: float, power_source_unit: string, conversion_method: string}
     */
    public static function normalize(float $value, string $unit): array
    {
        if ($value <= 0) {
            throw ValidationException::withMessages(['power_source_value' => 'VPWR-001: technical power must be greater than zero when known.']);
        }
        $unit = self::unit($unit);
        $kw = self::toKw($value, $unit);

        return [
            'power_kw' => round($kw, self::STORAGE_DECIMALS),
            'power_hp' => round($unit === 'MECHANICAL_HP' ? $value : $kw * self::KW_TO_MECHANICAL_HP, self::STORAGE_DECIMALS),
            'power_ps' => round($unit === 'METRIC_PS' ? $value : $kw * self::KW_TO_METRIC_PS, self::STORAGE_DECIMALS),
            'power_source_value' => round($value, self::STORAGE_DECIMALS),
            'power_source_unit' => $unit,
            'conversion_method' => self::CONVERSION_METHOD,
        ];
    }

    public static function display(?float $value, bool $sourceInteger = false): ?string
    {
        if ($value === null) {
            return null;
        }

        return $sourceInteger && floor($value) === $value ? (string) (int) $value : number_format($value, self::DISPLAY_DECIMALS, '.', '');
    }
}
