<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REQ-SET-005 governed configuration change (Draft→Review→Approved→Published). */
final class ConfigurationChangeSet extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['previous_value' => 'array', 'proposed_value' => 'array', 'effective_from' => 'date', 'submitted_at' => 'datetime', 'published_at' => 'datetime'];
    }
}
