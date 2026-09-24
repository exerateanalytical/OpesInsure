<?php

declare(strict_types=1);

namespace App\Models\Vehicles;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class VehicleReferenceValue extends Model
{
    use HasUuids;
    use ProtectsMasterData;

    protected $table = 'vehicle_reference_values';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort_order' => 'integer', 'admin_modified_at' => 'datetime'];
    }
}
