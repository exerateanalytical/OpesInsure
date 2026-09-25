<?php

declare(strict_types=1);

namespace App\Application\Cases\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CaseDecision extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'case_decisions';

    protected $guarded = [];

    protected $casts = ['conditions' => 'array', 'decided_at' => 'datetime'];
}
