<?php

declare(strict_types=1);

namespace App\Models\Vehicles;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Vehicle as insured, frozen at policy issue: foreign keys into the vehicle
 * master (stored once) plus a spec snapshot, so later master edits never
 * change what a policy covered. Write-once.
 */
final class PolicyVehicleSnapshot extends Model
{
    use HasUuids;

    protected $table = 'policy_vehicle_snapshots';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['spec_snapshot' => 'array', 'captured_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Policy vehicle snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Policy vehicle snapshots cannot be deleted.'));
    }
}
