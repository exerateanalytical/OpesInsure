<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class KycSubmission extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'party_id', 'status', 'notes', 'submitted_at', 'reviewed_at', 'reviewed_by',
        // REQ-KYC-001..003 (App\Application\Kyc\KycService is the only writer of these).
        'subject_kind', 'kyc_level', 'level_source', 'risk_factors', 'case_id', 'screening_status', 'recommended_outcome',
        'recommendation_rationale', 'recommended_by', 'recommended_at', 'decision_reason', 'approved_at', 'expires_at', 'expiry_basis',
        'expired_at', 'supersedes_submission_id', 'superseded_by_submission_id', 'remediation_reason', 'version'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'risk_factors' => 'array',
            'recommended_at' => 'datetime',
            'approved_at' => 'datetime',
            'expires_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'kyc_submission_documents')->withPivot('purpose');
    }
}
