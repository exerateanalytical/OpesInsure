<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Versioned seed datasets (e.g. CM_INSURANCE_MARKET 2026.1). */
final class SeedCatalogVersion extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'summary' => 'array'];
    }
}
