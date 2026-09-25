<?php

declare(strict_types=1);

namespace App\Application\Capabilities\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REQ-AOM-001 — immutable pinned mode on a transaction (DB trigger blocks UPDATE/DELETE). */
final class CapabilityPin extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'capability_pins';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pinned_at' => 'datetime', 'profile_version' => 'integer'];
    }
}
