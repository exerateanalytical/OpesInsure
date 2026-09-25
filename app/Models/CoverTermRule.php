<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REQ-PRP-005 — effective-date rules, durations and instalment plans for a product version (or line default). */
final class CoverTermRule extends Model
{
    use HasUuids;

    protected $fillable = ['insurance_product_id', 'line_code', 'effective_date_rules', 'default_effective_rule', 'durations', 'instalment_plans',
        'max_advance_days', 'instalment_fee_minor', 'non_payment_consequence', 'status', 'effective_from', 'effective_until', 'created_by'];

    protected function casts(): array
    {
        return ['effective_date_rules' => 'array', 'durations' => 'array', 'instalment_plans' => 'array', 'effective_from' => 'date', 'effective_until' => 'date',
            'max_advance_days' => 'integer', 'instalment_fee_minor' => 'integer'];
    }
}
