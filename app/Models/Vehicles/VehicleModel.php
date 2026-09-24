<?php

declare(strict_types=1);

namespace App\Models\Vehicles;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class VehicleModel extends Model
{
    use HasUuids;
    use ProtectsMasterData;

    protected $table = 'vehicle_models';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'admin_modified_at' => 'datetime'];
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'make_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(VehicleModelAlias::class, 'model_id');
    }

    public function generations(): HasMany
    {
        return $this->hasMany(VehicleGeneration::class, 'model_id');
    }
}
