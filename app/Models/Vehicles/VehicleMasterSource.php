<?php

declare(strict_types=1);

namespace App\Models\Vehicles;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class VehicleMasterSource extends Model
{
    use HasUuids;
    use ProtectsMasterData;

    protected $table = 'vehicle_master_sources';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['imported_at' => 'datetime'];
    }
}
