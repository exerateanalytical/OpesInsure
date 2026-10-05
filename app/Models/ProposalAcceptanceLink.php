<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Signed, expiring, single-use web contract-acceptance link sent to a customer by SMS (ProposalAcceptanceLinks). */
final class ProposalAcceptanceLink extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'proposal_id', 'party_id', 'phone_e164', 'token_hash', 'sms_status', 'expires_at', 'verified_at', 'used_at', 'created_by'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'verified_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function usable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
