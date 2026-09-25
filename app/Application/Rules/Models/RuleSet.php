<?php

declare(strict_types=1);

namespace App\Application\Rules\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** REQ-RUL-002 — versioned, effective-dated rule set (rule_sets). Immutable once out of DRAFT. */
final class RuleSet extends Model
{
    use HasUuids;

    protected $table = 'rule_sets';

    protected $guarded = [];

    protected $casts = ['version' => 'integer', 'effective_from' => 'date', 'effective_until' => 'date', 'approved_at' => 'datetime', 'retired_at' => 'datetime'];

    public function rules(): HasMany
    {
        return $this->hasMany(Rule::class);
    }
}
