<?php

declare(strict_types=1);

namespace App\Application\Vehicles\Power;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Automobile stamp duty fiscal-power bands (vehicle_fiscal_power_bands: CV_02_07, CV_08_13, CV_14_20, CV_GT_20).
 * VPWR-008: the band is derived from the verified fiscal_power_cv only — this class has no hp / kW / PS input.
 */
final class FiscalPowerBands
{
    /** @return list<object> */
    public function all(): array
    {
        return DB::table('vehicle_fiscal_power_bands')->orderBy('sort_order')->get()->all();
    }

    /** Band code for a fiscal CV, or null when the value is below the lowest band (no band = no automatic duty). */
    public function codeFor(int $fiscalPowerCv): ?string
    {
        if ($fiscalPowerCv <= 0) {
            throw new InvalidArgumentException('VPWR-004: fiscal_power_cv must be a positive integer.');
        }
        foreach ($this->all() as $band) {
            if ($fiscalPowerCv >= (int) $band->min_cv && ($band->max_cv === null || $fiscalPowerCv <= (int) $band->max_cv)) {
                return $band->code;
            }
        }

        return null;
    }
}
