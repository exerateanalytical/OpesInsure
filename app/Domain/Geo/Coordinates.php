<?php

declare(strict_types=1);

namespace App\Domain\Geo;

use Illuminate\Validation\ValidationException;

/**
 * The one latitude / longitude rule set (decimal degrees, WGS84): FNOL incident, customer address, quote risk answers.
 */
final class Coordinates
{
    public const RULES = ['latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180'];

    /**
     * Validates the latitude / longitude keys present in $values (nulls allowed) and returns them as floats, keyed
     * as given. Absent keys stay absent.
     *
     * @return array{latitude?: ?float, longitude?: ?float}
     */
    public static function pick(array $values, string $errorPrefix = ''): array
    {
        $out = [];
        foreach (['latitude' => 90, 'longitude' => 180] as $key => $max) {
            if (! array_key_exists($key, $values)) {
                continue;
            }
            $v = $values[$key];
            if ($v === null || $v === '') {
                $out[$key] = null;

                continue;
            }
            if (! is_numeric($v) || abs((float) $v) > $max) {
                throw ValidationException::withMessages([$errorPrefix.$key => __('validation.between.numeric', ['attribute' => $key, 'min' => -$max, 'max' => $max])]);
            }
            $out[$key] = round((float) $v, 7);
        }

        return $out;
    }
}
