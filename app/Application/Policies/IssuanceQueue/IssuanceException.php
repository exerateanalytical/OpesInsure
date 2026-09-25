<?php

declare(strict_types=1);

namespace App\Application\Policies\IssuanceQueue;

use App\Models\PaymentIntentRecord;
use App\Models\PolicyIssuanceRequest;
use App\Models\Proposal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row of the failed / paid-not-issued issuance queue (REQ-POL-004). */
final class IssuanceException extends Model
{
    use HasUuids;

    protected $table = 'issuance_exceptions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['blockers' => 'array', 'premium_cover' => 'array', 'attempts' => 'integer', 'last_attempt_at' => 'datetime', 'escalated_at' => 'datetime',
            'resolved_at' => 'datetime', 'customer_notified_at' => 'datetime'];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentIntentRecord::class, 'payment_intent_id');
    }

    public function issuanceRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyIssuanceRequest::class, 'policy_issuance_request_id');
    }
}
