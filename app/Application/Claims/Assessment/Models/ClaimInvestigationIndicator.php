<?php

declare(strict_types=1);

namespace App\Application\Claims\Assessment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Append-only snapshot of a fraud indicator referenced by an investigation (indicator engine owns the source). */
final class ClaimInvestigationIndicator extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'claim_investigation_indicators';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'attached_at' => 'datetime'];
    }
}
