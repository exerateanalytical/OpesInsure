<?php

declare(strict_types=1);

namespace App\Models\Vehicles;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class VehicleMasterChange extends Model
{
    use HasUuids;

    protected $table = 'vehicle_master_changes';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'occurred_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
