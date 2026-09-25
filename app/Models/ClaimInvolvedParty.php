<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The generic claim party (REQ-CLM-006). Mobile self-reported rows (source MOBILE) and staff-managed rows
 * (source STAFF, App\Application\Claims\Parties\ClaimPartyService) share this one table. Bank account numbers are
 * stored encrypted and only ever serialised masked.
 */
final class ClaimInvolvedParty extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'claim_id', 'party_id', 'partner_id', 'role', 'display_name', 'is_self', 'contact_phone',
        'contact_email', 'consent_given', 'consent_basis', 'consent_recorded_at', 'notes', 'added_by', 'updated_by',
        'source', 'match_status', 'match_candidates', 'bank_name', 'bank_account_holder', 'bank_account_encrypted',
        'bank_account_masked', 'effective_from', 'effective_to', 'removed_at', 'removed_by', 'removal_reason',
    ];

    protected $hidden = ['bank_account_encrypted'];

    protected function casts(): array
    {
        return [
            'is_self' => 'boolean', 'consent_given' => 'boolean', 'consent_recorded_at' => 'datetime',
            'bank_account_encrypted' => 'encrypted', 'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d',
            'removed_at' => 'datetime', 'match_candidates' => 'integer',
        ];
    }

    /** @param Builder<self> $q */
    public function scopeActive(Builder $q): void
    {
        $q->whereNull('removed_at');
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
