<?php

declare(strict_types=1);

namespace App\Models\Vehicles;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class VehicleModelAlias extends Model
{
    use HasUuids;
    use ProtectsMasterData;

    protected $table = 'vehicle_model_aliases';

    protected $guarded = ['id'];

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'model_id');
    }
}
