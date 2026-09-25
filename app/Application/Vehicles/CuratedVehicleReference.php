<?php

declare(strict_types=1);

namespace App\Application\Vehicles;

use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleMakeAlias;
use App\Models\Vehicles\VehicleMasterChange;
use App\Models\Vehicles\VehicleMasterReview;

/**
 * Owner decision 20 (2026-09-25): curated reference makes added by owner decision. They are labelled with the
 * curated-reference source (data_source OPESINSURE_CURATED_REFERENCE, provenance CURATED_REFERENCE) — never
 * OPESINSURE_VERIFIED_OVERRIDE — keep cameroon_status UNVERIFIED and get NO models: models are added later
 * through the master-data review, never invented. McLaren, Lada and UAZ are not here: they stay
 * PENDING_MASTER_REVIEW (VehicleMasterDataSeeder::CANDIDATE_MAKES).
 */
final class CuratedVehicleReference
{
    public const SOURCE = VehicleDataSource::CURATED_REFERENCE;

    public const PROVENANCE = 'CURATED_REFERENCE';

    /** code => [name, segment, country_of_origin] */
    public const MAKES = [
        'DATSUN' => ['Datsun', 'PASSENGER', 'JP'],
        'MAHINDRA' => ['Mahindra', 'PASSENGER', 'IN'],
    ];

    public function __construct(private readonly VehicleCatalogueService $catalogue) {}

    /** Idempotent: creates each missing make and closes its pending catalogue-candidate review. @return list<string> codes created */
    public function apply(): array
    {
        $created = [];
        foreach (self::MAKES as $code => [$name, $segment, $country]) {
            $normalized = VehicleText::normalize($name);
            $make = VehicleMake::where('code', $code)->first() ?? $this->catalogue->resolveMake($name)
                ?? VehicleMakeAlias::where('normalized_alias', $normalized)->first()?->make;
            if (! $make) {
                $make = VehicleMake::create(['code' => $code, 'name' => $name, 'normalized_name' => $normalized, 'segment' => $segment,
                    'country_of_origin' => $country, 'cameroon_status' => 'UNVERIFIED', 'market_priority' => 'NORMAL',
                    'provenance' => self::PROVENANCE, 'data_source' => self::SOURCE, 'active' => true]);
                VehicleMasterChange::create(['entity_type' => 'vehicle_make', 'entity_id' => $make->id, 'action' => 'SEEDED',
                    'after' => ['code' => $code, 'name' => $name, 'source' => self::SOURCE], 'reason' => 'Owner decision 20 (2026-09-25): curated reference make', 'occurred_at' => now()]);
                $created[] = $code;
            }
            VehicleMasterReview::where('make_text', $name)->where('model_text', '')->where('status', VehicleMasterReview::STATUS_PENDING)
                ->whereNull('submitted_by')->get()
                ->each(fn (VehicleMasterReview $r) => $r->update(['status' => 'APPROVED_NEW', 'resolved_make_id' => $make->id, 'reviewed_at' => now(),
                    'review_notes' => 'Owner decision 20 (2026-09-25): added to the curated reference (no models).']));
        }

        return $created;
    }
}
