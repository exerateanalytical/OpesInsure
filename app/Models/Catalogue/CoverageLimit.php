<?php

declare(strict_types=1);

namespace App\Models\Catalogue;

use App\Models\CoverageDefinition;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** REQ-PRD-005 — typed coverage limit (10 PRE §11 limit types). */
final class CoverageLimit extends Model
{
    use HasUuids;

    public const TYPES = ['UNLIMITED', 'FIXED_AMOUNT', 'PERCENT_OF_SUM_INSURED', 'PERCENT_OF_LOSS', 'PER_EVENT', 'PER_PERSON', 'PER_YEAR', 'PER_POLICY_PERIOD', 'AGGREGATE', 'SUB_LIMIT'];

    protected $table = 'coverage_limits';

    protected $fillable = ['insurance_product_id', 'coverage_definition_id', 'product_plan_id', 'limit_type', 'amount_minor', 'percentage_bp', 'currency', 'notes'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'percentage_bp' => 'integer'];
    }

    public function coverage(): BelongsTo
    {
        return $this->belongsTo(CoverageDefinition::class, 'coverage_definition_id');
    }
}
