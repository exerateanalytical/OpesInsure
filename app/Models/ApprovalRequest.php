<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** REQ-RBAC-005: one row per maker-checker request (all domains). Written only by ApprovalService. */
final class ApprovalRequest extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['context' => 'array', 'payload' => 'array', 'excluded_user_ids' => 'array', 'amount' => 'decimal:2', 'decided_at' => 'datetime'];
    }

    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }

    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }

    public function rule(): BelongsTo { return $this->belongsTo(ApprovalMatrixRule::class, 'matrix_rule_id'); }

    public function decisions(): HasMany { return $this->hasMany(ApprovalDecision::class); }
}
