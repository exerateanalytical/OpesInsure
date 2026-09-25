<?php

declare(strict_types=1);

namespace App\Models\Vehicles;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class VehicleGeneration extends Model
{
    use HasUuids;
    use HasDataSource;
    use ProtectsMasterData;

    protected $table = 'vehicle_generations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'year_from' => 'integer', 'year_to' => 'integer', 'admin_modified_at' => 'datetime'];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'model_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(VehicleVariant::class, 'generation_id');
    }
}
