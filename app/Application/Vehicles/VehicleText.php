<?php

declare(strict_types=1);

namespace App\Application\Vehicles;

use Illuminate\Support\Str;

final class VehicleText
{
    /** "Mercedes-Benz", "mercedes benz", "MERCEDES BENZ" → "MERCEDESBENZ". */
    public static function normalize(?string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii((string) $value))) ?? '';
    }

    /** "Land Cruiser Prado" → "LAND_CRUISER_PRADO"; "Lynk & Co" → "LYNK_CO". */
    public static function code(string $value): string
    {
        return trim((string) preg_replace('/[^A-Z0-9]+/', '_', Str::upper(Str::ascii($value))), '_');
    }
}
