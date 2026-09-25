<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** REQ-PRP-001 — append-only, hashed snapshot of what was submitted to underwriting (one row per submission). */
final class ProposalSubmission extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['proposal_id', 'sequence', 'kind', 'snapshot', 'snapshot_hash', 'submitted_by', 'submitted_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'submitted_at' => 'datetime', 'sequence' => 'integer'];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }
}
