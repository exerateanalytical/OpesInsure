<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Human underwriting decision. `decision` keeps the proposal-facing code (APPROVED/COUNTEROFFERED/DECLINED); `outcome` adds CONDITIONAL. */
final class UnderwritingDecision extends Model
{
    use HasUuids;

    protected $fillable = ['underwriting_case_id', 'decision', 'reason_code', 'notes', 'conditions', 'decided_by', 'decided_at', 'outcome', 'system_recommendation', 'engine_evaluation_id'];

    protected function casts(): array
    {
        return ['conditions' => 'array', 'decided_at' => 'datetime'];
    }

    public function underwritingCase(): BelongsTo
    {
        return $this->belongsTo(UnderwritingCase::class);
    }
}
