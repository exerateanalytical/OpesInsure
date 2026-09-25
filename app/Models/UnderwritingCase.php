<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Underwriting case (REQ-UW-001). Canonical state: App\Application\Underwriting\UnderwritingCaseMachine::canonicalState(). */
final class UnderwritingCase extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'proposal_id', 'carrier_id', 'status', 'priority', 'referral_reasons', 'assigned_to', 'decision_due_at',
        'outcome', 'recommendation', 'risk_score', 'risk_band', 'risk_factors', 'rule_set_versions', 'engine_evaluation_id', 'evaluated_at',
        'review_started_at', 'decision_pending_at'];

    protected function casts(): array
    {
        return ['referral_reasons' => 'array', 'decision_due_at' => 'datetime', 'risk_factors' => 'array', 'rule_set_versions' => 'array', 'risk_score' => 'integer',
            'evaluated_at' => 'datetime', 'review_started_at' => 'datetime', 'decision_pending_at' => 'datetime'];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(UnderwritingReferralTask::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(UnderwritingDecision::class);
    }
}
