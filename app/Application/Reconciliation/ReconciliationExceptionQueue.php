<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

use App\Application\Cases\CaseService;
use App\Application\Cases\Models\WorkCase;
use App\Application\Events\OutboxWriter;
use App\Models\ReconciliationItem;
use App\Models\User;

/**
 * REQ-PAY-007 exceptions queue on the case engine: every non-MATCHED statement line opens (idempotently) a
 * RECONCILIATION_EXCEPTION case routed by the QueueRouter; the case is closed when the line is resolved or
 * manually matched. Duplicates are also flagged as refund candidates (WF-026/027/086).
 */
final class ReconciliationExceptionQueue
{
    public const CASE_TYPE = 'RECONCILIATION_EXCEPTION';

    public function __construct(private readonly CaseService $cases, private readonly OutboxWriter $outbox) {}

    public function raise(ReconciliationItem $item, ?string $tenantId, ?User $actor): void
    {
        if ($item->outcome === 'DUPLICATE') {
            $this->flagRefundCandidate($item, 'DUPLICATE_PAYMENT');
        }
        $this->outbox->record('reconciliation.exception.raised', 'reconciliation_item', $item->id, [
            'reconciliation_import_id' => $item->reconciliation_import_id, 'outcome' => $item->outcome, 'exception_code' => $item->exception_code,
            'gross_minor' => (int) $item->gross_minor, 'currency' => $item->currency,
        ]);
        if ($tenantId === null) {
            return; // platform-level import: no tenant queue to route to
        }
        $case = $this->cases->open($tenantId, self::CASE_TYPE, [
            'title' => "Reconciliation {$item->outcome}: {$item->external_reference}",
            'priority' => in_array($item->outcome, ['DUPLICATE', 'OVER'], true) ? 'HIGH' : 'NORMAL',
            'subject_type' => 'RECONCILIATION_ITEM', 'subject_id' => $item->id,
            'source_type' => 'reconciliation_item', 'source_id' => $item->id, 'idempotency_key' => 'recon-item:'.$item->id,
        ], $actor);
        $item->update(['case_id' => $case->id]);
    }

    public function flagRefundCandidate(ReconciliationItem $item, string $reason): void
    {
        $item->update(['refund_candidate' => true, 'refund_reason' => $reason]);
        $this->outbox->record('reconciliation.refund_candidate.flagged', 'reconciliation_item', $item->id, [
            'reason' => $reason, 'amount_minor' => (int) $item->gross_minor, 'currency' => $item->currency,
            'matched_type' => $item->matched_type, 'matched_id' => $item->matched_id, 'external_reference' => $item->external_reference,
        ]);
        if (app()->bound(RefundCandidateSink::class)) {
            app(RefundCandidateSink::class)->refundCandidate($item, $reason, (int) $item->gross_minor, $item->currency);
        }
    }

    /** Close the item's exception case (OPEN/IN_PROGRESS -> RESOLVED -> CLOSED) with the given outcome. */
    public function close(ReconciliationItem $item, string $outcome, ?User $actor): void
    {
        if (! $item->case_id) {
            return;
        }
        $case = WorkCase::withoutGlobalScopes()->find($item->case_id);
        if (! $case || $case->closed_at !== null) {
            return;
        }
        if ($case->status === 'OPEN') {
            $case = $this->cases->transition($case, 'start', $actor);
        }
        if ($case->status === 'IN_PROGRESS') {
            $case = $this->cases->transition($case, 'resolve', $actor);
        }
        if ($case->status === 'RESOLVED') {
            $this->cases->transition($case, 'close', $actor, null, ['outcome' => $outcome]);
        }
    }
}
