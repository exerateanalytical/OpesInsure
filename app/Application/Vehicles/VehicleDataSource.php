<?php

declare(strict_types=1);

namespace App\Application\Vehicles;

/**
 * Source ranking for vehicle master rows (vehicle_master_config_africa_2026.json
 * "source_priority"). A lower-ranked source never overwrites a row owned by a
 * higher-ranked one, and nothing overwrites a row an admin edited.
 */
final class VehicleDataSource
{
    public const OVERRIDE = 'OPESINSURE_VERIFIED_OVERRIDE';

    public const DISTRIBUTOR = 'CAMEROON_DISTRIBUTOR_VERIFIED';

    public const GLOBAL_DATASET = 'GLOBAL_VEHICLE_DATASET';

    public const MANUAL_PENDING = 'MANUAL_PENDING_REVIEW';

    /** Highest priority first. */
    public const PRIORITY = [self::OVERRIDE, self::DISTRIBUTOR, self::GLOBAL_DATASET, self::MANUAL_PENDING];

    public static function rank(?string $source): int
    {
        $i = array_search($source, self::PRIORITY, true);

        return $i === false ? count(self::PRIORITY) : $i;
    }

    /** True when $incoming may replace data owned by $current. */
    public static function mayOverwrite(string $incoming, ?string $current): bool
    {
        return self::rank($incoming) <= self::rank($current);
    }

    public static function fromProvenance(?string $provenance): string
    {
        return match ($provenance) {
            'CUSTOMER_SUBMITTED' => self::MANUAL_PENDING,
            'CAMEROON_DISTRIBUTOR' => self::DISTRIBUTOR,
            'INDUSTRY_DATABASE' => self::GLOBAL_DATASET,
            default => self::OVERRIDE,
        };
    }
}
