<?php

declare(strict_types=1);

namespace App\Application\Claims\Assessment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REQ-CLM-010 — an assessor's recommendation (never a decision). Content is immutable (DB trigger). */
final class ClaimAssessment extends Model
{
    use HasUuids;

    protected $table = 'claim_assessments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['heads' => 'array', 'recommended_total_minor' => 'integer', 'reviewed_at' => 'datetime'];
    }
}
