<?php

declare(strict_types=1);

namespace App\Models\Vehicles;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class VehicleVariant extends Model
{
    use HasUuids;
    use HasDataSource;
    use ProtectsMasterData;

    protected $table = 'vehicle_variants';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'engine_capacity_cc' => 'integer', 'year_from' => 'integer', 'year_to' => 'integer', 'power_hp' => 'integer', 'power_kw' => 'float', 'torque_nm' => 'integer', 'cylinders' => 'integer', 'specs' => 'array', 'admin_modified_at' => 'datetime'];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'model_id');
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(VehicleGeneration::class, 'generation_id');
    }
}
