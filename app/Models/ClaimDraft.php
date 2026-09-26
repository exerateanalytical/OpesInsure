<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer's unfinished FNOL (see migration 2026_10_29_100001). Never a Claim until submitted. */
final class ClaimDraft extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'party_id', 'user_id', 'policy_id', 'payload', 'claim_id', 'submitted_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'submitted_at' => 'datetime'];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }
}
