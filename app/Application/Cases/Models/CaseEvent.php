<?php

declare(strict_types=1);

namespace App\Application\Cases\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CaseEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'case_events';

    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'occurred_at' => 'datetime'];
}
