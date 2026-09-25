<?php

declare(strict_types=1);

namespace App\Application\Cases\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class QueueMember extends Model
{
    use HasUuids;

    public $timestamps = true;

    protected $table = 'queue_members';

    protected $guarded = [];

    protected $casts = ['skills' => 'array', 'active' => 'boolean', 'last_assigned_at' => 'datetime'];
}
