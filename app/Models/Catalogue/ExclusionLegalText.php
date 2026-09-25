<?php

declare(strict_types=1);

namespace App\Models\Catalogue;

use App\Models\ExclusionDefinition;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** REQ-PRD-006 — versioned, effective-dated legal wording of an exclusion / extension. */
final class ExclusionLegalText extends Model
{
    use HasUuids;

    protected $fillable = ['exclusion_definition_id', 'version', 'text', 'legal_reference', 'effective_from', 'effective_until', 'status', 'text_hash',
        'created_by', 'approved_by', 'approved_at', 'recorded_at', 'superseded_at'];

    protected function casts(): array
    {
        return ['text' => 'array', 'effective_from' => 'date', 'effective_until' => 'date', 'approved_at' => 'datetime',
            'recorded_at' => 'datetime', 'superseded_at' => 'datetime', 'version' => 'integer'];
    }

    public function exclusion(): BelongsTo
    {
        return $this->belongsTo(ExclusionDefinition::class, 'exclusion_definition_id');
    }
}
