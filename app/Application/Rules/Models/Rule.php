<?php

declare(strict_types=1);

namespace App\Application\Rules\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Rule extends Model
{
    use HasUuids;

    protected $table = 'rules';

    protected $guarded = [];

    protected $casts = ['priority' => 'integer', 'stop_processing' => 'boolean', 'enabled' => 'boolean', 'condition' => 'json', 'outcome' => 'array'];
}
