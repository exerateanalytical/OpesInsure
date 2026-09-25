<?php

declare(strict_types=1);

namespace App\Application\Cases\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SlaClock extends Model
{
    use HasUuids;

    public $timestamps = true;

    protected $table = 'sla_clocks';

    protected $guarded = [];

    protected $casts = ['started_at' => 'datetime', 'paused_since' => 'datetime', 'due_at' => 'datetime', 'warn_at' => 'datetime', 'warned_at' => 'datetime', 'breached_at' => 'datetime', 'stopped_at' => 'datetime'];
}
