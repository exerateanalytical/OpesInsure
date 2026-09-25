<?php

declare(strict_types=1);

namespace App\Application\Cases\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class WorkQueue extends Model
{
    use HasUuids;

    public $timestamps = true;

    protected $table = 'queues';

    protected $guarded = [];

    protected $casts = ['case_type_codes' => 'array', 'active' => 'boolean', 'is_default' => 'boolean'];
}
