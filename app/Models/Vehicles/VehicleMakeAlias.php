<?php

declare(strict_types=1);

namespace App\Models\Vehicles;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class VehicleMakeAlias extends Model
{
    use HasUuids;
    use ProtectsMasterData;

    protected $table = 'vehicle_make_aliases';

    protected $guarded = ['id'];

    public function make(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'make_id');
    }
}
