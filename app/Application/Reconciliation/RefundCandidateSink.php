<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

use App\Models\ReconciliationItem;

/**
 * Guarded hand-off to the refund engine (Batch 9-6). The reconciliation side always flags the item
 * (refund_candidate = true, refund_reason) and emits reconciliation.refund_candidate.flagged; when the refund
 * engine binds an implementation of this interface in the container it is also called synchronously.
 */
interface RefundCandidateSink
{
    public function refundCandidate(ReconciliationItem $item, string $reason, int $amountMinor, string $currency): void;
}
