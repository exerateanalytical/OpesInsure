<?php

declare(strict_types=1);

namespace App\Application\Fraud;

use App\Application\Audit\AuditWriter;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-FRD-002 — cash-fraud control: segregation of duties over the same money (one payment intent).
 *
 *   COLLECT    cashier_collections.collected_by (Batch 9 cashier sessions)
 *   RECONCILE  reconciliation_manual_matches.requested_by / decided_by (not REJECTED) and reconciliation_items.resolved_by
 *   REFUND     refunds.calculated_by / reviewed_by / approved_by / paid_by / reconciled_by (raising a candidate is not a duty)
 *
 * A user who performed one duty on a payment may not perform another on the same payment (assertMay, called by
 * CashierSessionService, ManualMatchService and RefundEngine). violations() reports historical conflicts.
 */
final class SegregationOfDutiesPolicy
{
    public const COLLECT = 'COLLECT';

    public const RECONCILE = 'RECONCILE';

    public const REFUND = 'REFUND';

    public const DUTIES = [self::COLLECT, self::RECONCILE, self::REFUND];

    public function __construct(private readonly AuditWriter $audit) {}

    /** @return list<string> duties this user already performed on the payment */
    public function dutiesOf(string $paymentIntentId, string $userId): array
    {
        return $this->duties(null, $paymentIntentId)->where('user_id', $userId)->pluck('duty')->unique()->sort()->values()->all();
    }

    /** Null = allowed; otherwise the conflicting duty already performed. */
    public function conflict(string $duty, string $paymentIntentId, string $userId): ?string
    {
        foreach ($this->dutiesOf($paymentIntentId, $userId) as $done) {
            if ($done !== $duty) {
                return $done;
            }
        }

        return null;
    }

    public function assertMay(string $duty, ?string $paymentIntentId, User $actor): void
    {
        if ($paymentIntentId === null || ! in_array($duty, self::DUTIES, true)) {
            return;
        }
        if ($done = $this->conflict($duty, $paymentIntentId, $actor->id)) {
            $this->audit->record('fraud.sod.blocked', 'payment_intent', $paymentIntentId, ['duty' => $duty, 'conflicting_duty' => $done, 'user_id' => $actor->id]);
            throw ValidationException::withMessages(['actor' => "SOD_VIOLATION: you performed {$done} on this payment and may not also perform {$duty}."]);
        }
    }

    /**
     * SoD violation report: every (payment, user) pair holding more than one duty.
     *
     * @return list<array{payment_intent_id:string,user_id:string,duties:list<string>,evidence:list<array>}>
     */
    public function violations(string $tenantId): array
    {
        $out = [];
        foreach ($this->duties($tenantId, null)->groupBy(fn ($r) => $r->payment_intent_id.'|'.$r->user_id) as $rows) {
            $duties = $rows->pluck('duty')->unique()->sort()->values()->all();
            if (count($duties) > 1) {
                $first = $rows->first();
                $out[] = ['payment_intent_id' => $first->payment_intent_id, 'user_id' => $first->user_id, 'duties' => $duties,
                    'evidence' => $rows->map(fn ($r) => ['duty' => $r->duty, 'source_type' => $r->source_type, 'source_id' => $r->source_id])->values()->all()];
            }
        }

        return $out;
    }

    /** @return Collection<int, object{payment_intent_id:string,user_id:string,duty:string,source_type:string,source_id:string}> */
    private function duties(?string $tenantId, ?string $paymentIntentId): Collection
    {
        $scope = function ($q, string $tenantCol, string $paymentCol) use ($tenantId, $paymentIntentId) {
            return $q->when($tenantId, fn ($q) => $q->where($tenantCol, $tenantId))->when($paymentIntentId, fn ($q) => $q->where($paymentCol, $paymentIntentId));
        };
        $rows = collect();
        $rows = $rows->concat($scope(DB::table('cashier_collections')->whereNotNull('payment_intent_id'), 'tenant_id', 'payment_intent_id')
            ->get(['payment_intent_id', 'collected_by as user_id', 'id as source_id'])
            ->map(fn ($r) => (object) [...(array) $r, 'duty' => self::COLLECT, 'source_type' => 'cashier_collection']));
        foreach (['requested_by', 'decided_by'] as $col) {
            $rows = $rows->concat($scope(DB::table('reconciliation_manual_matches')->where('matched_type', 'PAYMENT_INTENT')->where('status', '<>', 'REJECTED')->whereNotNull($col), 'tenant_id', 'matched_id')
                ->get(['matched_id as payment_intent_id', "{$col} as user_id", 'id as source_id'])
                ->map(fn ($r) => (object) [...(array) $r, 'duty' => self::RECONCILE, 'source_type' => 'reconciliation_manual_match']));
        }
        $items = DB::table('reconciliation_items as i')->join('reconciliation_imports as m', 'm.id', '=', 'i.reconciliation_import_id')
            ->where('i.matched_type', 'PAYMENT_INTENT')->whereNotNull('i.resolved_by');
        $rows = $rows->concat($scope($items, 'm.tenant_id', 'i.matched_id')
            ->get(['i.matched_id as payment_intent_id', 'i.resolved_by as user_id', 'i.id as source_id'])
            ->map(fn ($r) => (object) [...(array) $r, 'duty' => self::RECONCILE, 'source_type' => 'reconciliation_item']));
        foreach (['calculated_by', 'reviewed_by', 'approved_by', 'paid_by', 'reconciled_by'] as $col) {
            $rows = $rows->concat($scope(DB::table('refunds')->whereNotNull($col), 'tenant_id', 'payment_intent_id')
                ->get(['payment_intent_id', "{$col} as user_id", 'id as source_id'])
                ->map(fn ($r) => (object) [...(array) $r, 'duty' => self::REFUND, 'source_type' => 'refund']));
        }

        return $rows->values();
    }
}
