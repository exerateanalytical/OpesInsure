<?php

declare(strict_types=1);

namespace App\Application\Capabilities\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REQ-AOM-001 — one capability's mode inside a profile (carrier default, class or product override). */
final class CapabilityMode extends Model
{
    use HasUuids;

    protected $table = 'carrier_capability_modes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['config' => 'array'];
    }
}
