<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REQ-RBAC-006 central approval matrix row (tenant_id NULL = platform default). */
final class ApprovalMatrixRule extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['checker_roles' => 'array', 'requires_maker_checker' => 'boolean', 'exclude_subject_parties' => 'boolean',
            'effective_from' => 'date', 'effective_to' => 'date', 'min_amount' => 'decimal:2', 'max_amount' => 'decimal:2'];
    }
}
