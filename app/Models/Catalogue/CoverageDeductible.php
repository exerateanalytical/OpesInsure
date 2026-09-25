<?php

declare(strict_types=1);

namespace App\Models\Catalogue;

use App\Models\CoverageDefinition;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** REQ-PRD-005 — typed deductible (FIXED, PERCENTAGE, DAYS, COMBINED e.g. 5% min 50 000, MINIMUM, MAXIMUM). */
final class CoverageDeductible extends Model
{
    use HasUuids;

    public const TYPES = ['FIXED', 'PERCENTAGE', 'DAYS', 'COMBINED', 'MINIMUM', 'MAXIMUM'];

    protected $table = 'coverage_deductibles';

    protected $fillable = ['insurance_product_id', 'coverage_definition_id', 'product_plan_id', 'deductible_type', 'amount_minor', 'percentage_bp', 'percentage_basis',
        'days', 'minimum_minor', 'maximum_minor', 'currency', 'notes'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'percentage_bp' => 'integer', 'days' => 'integer', 'minimum_minor' => 'integer', 'maximum_minor' => 'integer'];
    }

    public function coverage(): BelongsTo
    {
        return $this->belongsTo(CoverageDefinition::class, 'coverage_definition_id');
    }
}
