<?php

declare(strict_types=1);

namespace App\Application\Risks;

use App\Application\Catalogue\RiskSchemaCatalogue;
use App\Application\Vehicles\RiskAssetVehicleSync;

/**
 * REQ-RSK-001: the single catalogue of insured-object types a risk_assets row
 * may carry. Each type names the insurance-line risk schema whose master-data
 * fields (select_master / multi_select_master) its facts are validated
 * against by RiskFactsProcessor — that is how a non-vehicle object is linked
 * to master data. Vehicles are linked through risk_asset_vehicles instead
 * (RiskAssetVehicleSync: make/model/generation/variant ids).
 */
final class RiskAssetTypes
{
    /** type => [category, schema line code|null, label] */
    public const TYPES = [
        'VEHICLE' => ['VEHICLE', 'MOTOR', 'Vehicle'],
        'PROPERTY' => ['PROPERTY', 'HOME', 'Property / building'],
        'BUSINESS' => ['PROPERTY', 'BUSINESS', 'Business premises / stock'],
        'CONSTRUCTION' => ['PROPERTY', 'CONSTRUCTION', 'Construction project'],
        'PERSON' => ['PERSON', 'ACCIDENT', 'Insured person'],
        'TRAVELLER' => ['PERSON', 'TRAVEL', 'Traveller'],
        'HEALTH_MEMBER' => ['PERSON', 'HEALTH', 'Health plan member'],
        'LIFE_ASSURED' => ['PERSON', 'LIFE', 'Life assured'],
        'CARGO' => ['CARGO', 'CARGO', 'Cargo / shipment'],
        'EQUIPMENT' => ['EQUIPMENT', 'EQUIPMENT', 'Equipment / machinery'],
        'LIABILITY' => ['LIABILITY', 'PROFESSIONAL_LIABILITY', 'Liability exposure'],
        'CROP' => ['AGRICULTURE', 'AGRICULTURE', 'Crop / farm'],
        'LIVESTOCK' => ['AGRICULTURE', 'LIVESTOCK', 'Livestock'],
        'AQUACULTURE' => ['AGRICULTURE', 'AQUACULTURE', 'Aquaculture stock'],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::TYPES);
    }

    public static function isVehicle(string $type): bool
    {
        return in_array(strtoupper($type), RiskAssetVehicleSync::VEHICLE_TYPES, true);
    }

    public static function lineFor(string $type): ?string
    {
        return self::TYPES[strtoupper($type)][1] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public static function describe(): array
    {
        return array_map(fn (string $code, array $t) => [
            'code' => $code,
            'category' => $t[0],
            'label' => $t[2],
            'line_code' => $t[1],
            'master_data_link' => $code === 'VEHICLE' ? 'risk_asset_vehicles' : 'risk_schema:'.$t[1],
            'schema_available' => RiskSchemaCatalogue::for($t[1]) !== null,
        ], array_keys(self::TYPES), self::TYPES);
    }
}
