<?php

declare(strict_types=1);

namespace App\Models\Vehicles;

use App\Models\RiskAsset;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RiskAssetVehicle extends Model
{
    use HasUuids;

    protected $table = 'risk_asset_vehicles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'model_year' => 'integer', 'engine_capacity_cc' => 'integer', 'horsepower' => 'integer', 'fiscal_power' => 'integer', 'seat_count' => 'integer',
            'odometer_km' => 'integer', 'purchase_value' => 'integer', 'declared_value' => 'integer', 'market_value' => 'integer', 'assessed_value' => 'integer', 'sum_insured' => 'integer',
            'gross_vehicle_weight_kg' => 'integer', 'payload_kg' => 'integer', 'axle_count' => 'integer', 'electric_range_km' => 'integer',
            'first_registration_date' => 'date', 'import_date' => 'date',
        ];
    }

    public function riskAsset(): BelongsTo
    {
        return $this->belongsTo(RiskAsset::class);
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'make_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'model_id');
    }
}
