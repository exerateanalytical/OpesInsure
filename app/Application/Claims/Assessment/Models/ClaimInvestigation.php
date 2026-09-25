<?php

declare(strict_types=1);

namespace App\Application\Claims\Assessment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** REQ-CLM-010 (WF-055) — claim investigation, opened as a CLAIM_INVESTIGATION case. Frozen once CONCLUDED (DB trigger). */
final class ClaimInvestigation extends Model
{
    use HasUuids;

    protected $table = 'claim_investigations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['concluded_at' => 'datetime'];
    }

    public function indicators(): HasMany
    {
        return $this->hasMany(ClaimInvestigationIndicator::class, 'investigation_id')->orderBy('attached_at');
    }
}
