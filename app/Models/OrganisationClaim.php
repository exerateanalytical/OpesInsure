<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Claim this organisation" request for an official-register insurer or broker. Written only by OrganisationClaimService. */
final class OrganisationClaim extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['code_hash', 'status_token_hash', 'ip_hash'];

    protected function casts(): array
    {
        return [
            'documents' => 'array', 'result' => 'array', 'is_dispute' => 'boolean',
            'code_expires_at' => 'datetime', 'code_sent_at' => 'datetime', 'verified_at' => 'datetime', 'reviewed_at' => 'datetime', 'decided_at' => 'datetime',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
