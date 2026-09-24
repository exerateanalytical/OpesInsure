<?php

declare(strict_types=1);

namespace App\Application\Vehicles;

use App\Models\RiskAsset;
use App\Models\Vehicles\RiskAssetVehicle;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleMasterReview;
use App\Models\Vehicles\VehicleModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps the canonical vehicle record (risk_asset_vehicles) in step with a
 * VEHICLE risk asset's facts. Facts stay the rating input and the source of
 * truth for what the customer typed; the record adds reconciled make/model
 * ids (by code, then name/alias) while keeping make_text/model_text as the
 * snapshot. Unresolved text waits for the review queue or a later reseed.
 */
final class RiskAssetVehicleSync
{
    public const VEHICLE_TYPES = ['VEHICLE', 'MOTOR', 'CAR', 'MOTORCYCLE'];

    /** fact key(s) => column. First present fact wins. */
    private const FACT_COLUMNS = [
        'model_year' => ['model_year', 'year'],
        'body_type' => ['body_type'],
        'vehicle_class' => ['vehicle_class'],
        'usage' => ['vehicle_usage', 'usage'],
        'ownership' => ['ownership', 'ownership_type'],
        'powertrain' => ['powertrain', 'fuel_type'],
        'hybrid_subtype' => ['hybrid_subtype'],
        'transmission' => ['transmission'],
        'drive_type' => ['drive_type'],
        'condition' => ['condition', 'vehicle_condition'],
        'registration_number' => ['registration_number'],
        'vin' => ['vin', 'chassis_number'],
        'engine_number' => ['engine_number'],
        'engine_capacity_cc' => ['engine_capacity_cc'],
        'engine_power_kw' => ['engine_power_kw'],
        'horsepower' => ['horsepower'],
        'fiscal_power' => ['fiscal_power'],
        'seat_count' => ['seat_count', 'seats'],
        'first_registration_date' => ['first_registration_date'],
        'import_date' => ['import_date'],
        'country_of_origin' => ['country_of_origin'],
        'odometer_km' => ['odometer_km', 'odometer'],
        'purchase_value' => ['purchase_value'],
        'declared_value' => ['declared_value', 'vehicle_value'],
        'market_value' => ['market_value'],
        'assessed_value' => ['assessed_value'],
        'sum_insured' => ['sum_insured'],
        'gross_vehicle_weight_kg' => ['gross_vehicle_weight_kg'],
        'payload_kg' => ['payload_kg'],
        'axle_count' => ['axle_count'],
        'cargo_type' => ['cargo_type'],
        'trailer_type' => ['trailer_type'],
        'commercial_activity' => ['commercial_activity'],
        'battery_capacity_kwh' => ['battery_capacity_kwh'],
        'battery_type' => ['battery_type'],
        'electric_range_km' => ['electric_range_km'],
        'charging_type' => ['charging_type'],
        'battery_ownership' => ['battery_ownership'],
    ];

    private const INTEGER_COLUMNS = ['model_year', 'engine_capacity_cc', 'horsepower', 'fiscal_power', 'seat_count', 'odometer_km', 'purchase_value', 'declared_value', 'market_value', 'assessed_value', 'sum_insured', 'gross_vehicle_weight_kg', 'payload_kg', 'axle_count', 'electric_range_km'];

    private const DECIMAL_COLUMNS = ['engine_power_kw', 'battery_capacity_kwh'];

    private const DATE_COLUMNS = ['first_registration_date', 'import_date'];

    public function __construct(private VehicleCatalogueService $catalogue) {}

    public function sync(RiskAsset $asset): ?RiskAssetVehicle
    {
        if (! in_array(strtoupper((string) $asset->type), self::VEHICLE_TYPES, true)) {
            return null;
        }

        $facts = is_array($asset->facts) ? $asset->facts : [];
        $record = RiskAssetVehicle::firstOrNew(['risk_asset_id' => $asset->id]);

        foreach (self::FACT_COLUMNS as $column => $keys) {
            foreach ($keys as $key) {
                if (! array_key_exists($key, $facts) || $facts[$key] === null || $facts[$key] === '') {
                    continue;
                }
                $value = $this->cast($column, $facts[$key]);
                if ($value !== null) {
                    $record->{$column} = $value;
                }
                break;
            }
        }

        $makeText = $this->text($facts['make'] ?? $facts['vehicle_make'] ?? null);
        $modelText = $this->text($facts['model'] ?? $facts['vehicle_model'] ?? null);
        $record->make_text = $makeText ?? $record->make_text;
        $record->model_text = $modelText ?? $record->model_text;

        $make = $this->text($facts['make_code'] ?? null) ? VehicleMake::where('code', strtoupper((string) $facts['make_code']))->first() : null;
        $make ??= $this->catalogue->resolveMake($record->make_text);
        $model = null;
        if ($make) {
            $modelCode = $this->text($facts['model_code'] ?? null);
            $model = $modelCode ? VehicleModel::where('code', strtoupper($modelCode))->where('make_id', $make->id)->first() : null;
            $model ??= $this->catalogue->resolveModel($make, $record->model_text);
        }

        // Manual "not listed" entry submitted from the picker before the asset existed: link it.
        $reviewId = $this->text($facts['vehicle_review_id'] ?? null);
        if ($reviewId && ! $record->review_id && Str::isUuid($reviewId)
            && ($review = VehicleMasterReview::where('id', $reviewId)->where(fn ($q) => $q->whereNull('risk_asset_id')->orWhere('risk_asset_id', $asset->id))->first())) {
            $record->review_id = $review->id;
            $review->update(['risk_asset_id' => $asset->id, 'tenant_id' => $review->tenant_id ?? $asset->tenant_id]);
            if (! $make && $review->resolved_make_id && $review->status !== VehicleMasterReview::STATUS_PENDING) {
                $make = VehicleMake::find($review->resolved_make_id);
                $model = $review->resolved_model_id ? VehicleModel::find($review->resolved_model_id) : null;
            }
        }

        $record->make_id = $make?->id;
        $record->model_id = $model?->id;
        if ($make && ! $record->country_of_origin) {
            $record->country_of_origin = $make->country_of_origin;
        }
        $record->reconciliation_status = match (true) {
            $make && $model => 'MATCHED',
            $record->review_id !== null => 'PENDING_REVIEW',
            $make !== null => 'PARTIAL',
            default => 'UNRESOLVED',
        };

        $record->save();

        return $record;
    }

    /** Creates missing records and re-resolves unmatched ones. Returns records touched. */
    public function reconcileAll(): int
    {
        $n = 0;
        RiskAsset::whereIn('type', self::VEHICLE_TYPES)
            ->where(function ($q) {
                $q->whereNotIn('id', RiskAssetVehicle::select('risk_asset_id'))
                    ->orWhereIn('id', RiskAssetVehicle::whereIn('reconciliation_status', ['UNRESOLVED', 'PARTIAL'])->select('risk_asset_id'));
            })
            ->chunkById(200, function ($assets) use (&$n) {
                foreach ($assets as $asset) {
                    $this->sync($asset);
                    $n++;
                }
            });

        return $n;
    }

    private function text(mixed $v): ?string
    {
        return is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
    }

    private function cast(string $column, mixed $value): mixed
    {
        try {
            return match (true) {
                in_array($column, self::INTEGER_COLUMNS, true) => is_numeric($value) ? (int) $value : null,
                in_array($column, self::DECIMAL_COLUMNS, true) => is_numeric($value) ? (float) $value : null,
                in_array($column, self::DATE_COLUMNS, true) => is_string($value) ? Carbon::parse($value)->toDateString() : null,
                $column === 'country_of_origin' => is_string($value) && preg_match('/^[A-Za-z]{2}$/', trim($value)) ? strtoupper(trim($value)) : null,
                default => is_scalar($value) ? mb_substr(trim((string) $value), 0, match ($column) {
                    'hybrid_subtype' => 10, 'registration_number', 'vin', 'battery_type', 'charging_type' => 40, 'battery_ownership' => 20, 'commercial_activity' => 120, default => 60,
                }) : null,
            };
        } catch (Throwable) {
            return null;
        }
    }
}
