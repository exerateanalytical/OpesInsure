<?php

declare(strict_types=1);

namespace App\Application\Vehicles;

/**
 * Bridges the 28 canonical vehicle usages onto the tariff fact `usage_type`
 * (PRIVATE | COMMERCIAL) that current MOTOR tariffs rate on, until tariffs
 * are extended to the full usage list. Private personal/family use is
 * PRIVATE; every other use (taxi, fleet, goods, government, …) is
 * professional use and maps to COMMERCIAL. Make, model and country of origin
 * are never rating inputs.
 */
final class VehicleUsageMapper
{
    public const PRIVATE_USAGES = ['PRIVATE_PERSONAL', 'PRIVATE_FAMILY'];

    public static function toUsageType(string $usage): string
    {
        return in_array(strtoupper($usage), self::PRIVATE_USAGES, true) ? 'PRIVATE' : 'COMMERCIAL';
    }

    /**
     * Adds usage_type from vehicle_usage when the client did not send one;
     * an explicit usage_type (legacy clients, TAXI/TRANSPORT tariffs) wins.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public static function withDerivedFacts(array $facts): array
    {
        if (! array_key_exists('usage_type', $facts) && is_string($facts['vehicle_usage'] ?? null) && $facts['vehicle_usage'] !== '') {
            $facts['usage_type'] = self::toUsageType($facts['vehicle_usage']);
        }

        return $facts;
    }
}
