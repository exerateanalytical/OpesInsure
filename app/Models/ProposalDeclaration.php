<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** REQ-PRP-002 — append-only declaration / attestation / consent with market-conduct evidence. */
final class ProposalDeclaration extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['proposal_id', 'code', 'text_version', 'text_hash', 'statement', 'legal_status', 'channel', 'evidence', 'accepted_by', 'accepted_at'];

    protected function casts(): array
    {
        return ['statement' => 'array', 'evidence' => 'array', 'accepted_at' => 'datetime'];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }
}
