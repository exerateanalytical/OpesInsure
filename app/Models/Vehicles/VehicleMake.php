<?php

declare(strict_types=1);

namespace App\Models\Vehicles;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class VehicleMake extends Model
{
    use HasUuids;
    use HasDataSource;
    use ProtectsMasterData;

    protected $table = 'vehicle_makes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'ui_rank_cameroon' => 'integer', 'ui_rank_chinese' => 'integer', 'admin_modified_at' => 'datetime'];
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(VehicleMakeAlias::class, 'make_id');
    }

    public function models(): HasMany
    {
        return $this->hasMany(VehicleModel::class, 'make_id');
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(VehicleManufacturer::class, 'manufacturer_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }
}
