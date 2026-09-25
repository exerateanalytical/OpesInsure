<?php

declare(strict_types=1);

namespace App\Application\Policies\Cancellation;

use App\Models\Policy;
use App\Models\PolicyTransaction;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** REQ-CAN-001 cancellation case (table policy_cancellations). Transitions only through CancellationService. */
final class PolicyCancellation extends Model
{
    use HasUuids;

    protected $table = 'policy_cancellations';

    protected $fillable = [
        'tenant_id', 'policy_id', 'policy_transaction_id', 'status', 'initiated_by', 'reason_code', 'effective_at',
        'refund_basis', 'refund_minor', 'currency', 'notice_days', 'notice_served_at', 'requested_by',
        'reviewed_by', 'reviewed_at', 'review_note', 'decided_by', 'decided_at', 'decision_note',
        'authority_check_id', 'policy_version_id', 'refund_id', 'documents_revoked',
    ];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime', 'notice_served_at' => 'datetime', 'reviewed_at' => 'datetime',
            'decided_at' => 'datetime', 'refund_minor' => 'integer', 'notice_days' => 'integer', 'documents_revoked' => 'integer',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PolicyTransaction::class, 'policy_transaction_id');
    }
}
