<?php

declare(strict_types=1);

namespace App\Models\Regulatory;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REQ-SEED-003 — append-only, effective-dated name/brand history of a carrier or partner. */
final class OrganizationNameHistory extends Model
{
    use HasUuids;

    protected $table = 'organization_name_histories';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'reference_year' => 'integer'];
    }
}
