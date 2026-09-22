<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ClaimInvolvedParty extends Model
{
    use HasUuids;

    protected $fillable = [
        'claim_id', 'role', 'display_name', 'is_self', 'contact_phone',
        'contact_email', 'consent_given', 'notes', 'added_by',
    ];

    protected function casts(): array
    {
        return ['is_self' => 'boolean', 'consent_given' => 'boolean'];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }
}
