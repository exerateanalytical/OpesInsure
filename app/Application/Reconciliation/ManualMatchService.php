<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\PaymentIntentRecord;
use App\Models\ReconciliationItem;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PAY-007 unmatched-items workspace: search the exception lines, list candidate payments for a line, and
 * manually match a line to a payment under maker-checker (request -> independent approve / reject).
 */
final class ManualMatchService
{
    public function __construct(
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
        private readonly ReconciliationExceptionQueue $queue,
        private readonly ReconciliationService $reconciliation,
    ) {}

    /** @param array<string, mixed> $f */
    public function search(string $tenantId, array $f): LengthAwarePaginator
    {
        return $this->scoped($tenantId)
            ->where('status', $f['status'] ?? 'EXCEPTION')
            ->when($f['outcome'] ?? null, fn ($q, $v) => $q->where('outcome', $v))
            ->when($f['reference'] ?? null, fn ($q, $v) => $q->where('external_reference', 'ilike', '%'.addcslashes($v, '%_\\').'%'))
            ->when($f['import_id'] ?? null, fn ($q, $v) => $q->where('reconciliation_import_id', $v))
            ->when(isset($f['min_minor']), fn ($q) => $q->where('gross_minor', '>=', (int) $f['min_minor']))
            ->when(isset($f['max_minor']), fn ($q) => $q->where('gross_minor', '<=', (int) $f['max_minor']))
            ->when(isset($f['refund_candidate']), fn ($q) => $q->where('refund_candidate', (bool) $f['refund_candidate']))
            ->orderByDesc('transaction_at')->paginate(min((int) ($f['per_page'] ?? 25), 100));
    }

    public function item(string $tenantId, string $id): ReconciliationItem
    {
        return $this->scoped($tenantId)->findOrFail($id);
    }

    /** Payments of the tenant in the line's currency whose amount equals the line or whose reference resembles it. */
    public function candidates(string $tenantId, ReconciliationItem $item): Collection
    {
        return PaymentIntentRecord::where('tenant_id', $tenantId)->where('currency', $item->currency)
            ->where(fn ($q) => $q->where('amount_minor', (int) $item->gross_minor)
                ->orWhere('provider_reference', 'ilike', '%'.addcslashes(Str::limit($item->external_reference, 40, ''), '%_\\').'%'))
            ->orderByDesc('created_at')->limit(20)->get(['id', 'provider', 'provider_reference', 'amount_minor', 'currency', 'status', 'proposal_id', 'reconciled_at', 'created_at']);
    }

    public function request(string $tenantId, ReconciliationItem $item, array $d, User $maker): object
    {
        if ($item->status !== 'EXCEPTION') {
            throw ValidationException::withMessages(['status' => 'Only an open exception line can be manually matched.']);
        }
        $payment = PaymentIntentRecord::where('tenant_id', $tenantId)->find($d['matched_id']);
        if (! $payment || $payment->currency !== $item->currency) {
            throw ValidationException::withMessages(['matched_id' => 'Payment not found in this tenant or currency differs.']);
        }
        if (DB::table('reconciliation_manual_matches')->where('reconciliation_item_id', $item->id)->where('status', 'PENDING')->exists()) {
            throw ValidationException::withMessages(['item' => 'A manual match is already pending for this line.']);
        }
        // REQ-FRD-002 (agent C16): the collector / refunder of this money may not reconcile it.
        app(\App\Application\Fraud\SegregationOfDutiesPolicy::class)->assertMay('RECONCILE', $payment->id, $maker);

        return DB::transaction(function () use ($tenantId, $item, $d, $maker, $payment) {
            $id = (string) Str::uuid();
            DB::table('reconciliation_manual_matches')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'reconciliation_item_id' => $item->id, 'matched_type' => 'PAYMENT_INTENT', 'matched_id' => $payment->id,
                'notes' => $d['notes'], 'status' => 'PENDING', 'requested_by' => $maker->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('reconciliation.manual_match.requested', 'reconciliation_item', $item->id, ['manual_match_id' => $id, 'payment_intent_id' => $payment->id], $d['notes']);
            $this->outbox->record('reconciliation.manual_match.requested', 'reconciliation_item', $item->id, ['manual_match_id' => $id, 'payment_intent_id' => $payment->id]);

            return DB::table('reconciliation_manual_matches')->find($id);
        });
    }

    public function decide(string $tenantId, string $matchId, bool $approve, string $note, User $checker): object
    {
        return DB::transaction(function () use ($tenantId, $matchId, $approve, $note, $checker) {
            $m = DB::table('reconciliation_manual_matches')->where('tenant_id', $tenantId)->where('id', $matchId)->lockForUpdate()->first();
            if (! $m) {
                throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel('reconciliation_manual_match', [$matchId]);
            }
            if ($m->status !== 'PENDING') {
                throw ValidationException::withMessages(['status' => 'Manual match already decided.']);
            }
            if ($m->requested_by === $checker->id) {
                throw ValidationException::withMessages(['actor' => __('wave4.maker_checker')]);
            }
            if ($approve && $m->matched_type === 'PAYMENT_INTENT') {
                app(\App\Application\Fraud\SegregationOfDutiesPolicy::class)->assertMay('RECONCILE', $m->matched_id, $checker); // REQ-FRD-002
            }
            $item =ReconciliationItem::lockForUpdate()->findOrFail($m->reconciliation_item_id);
            $status = $approve ? 'APPROVED' : 'REJECTED';
            if ($approve) {
                if ($item->status !== 'EXCEPTION') {
                    throw ValidationException::withMessages(['status' => 'The line is no longer an open exception.']);
                }
                $item->update([
                    'status' => 'MATCHED', 'outcome' => 'MATCHED', 'matched_type' => $m->matched_type, 'matched_id' => $m->matched_id, 'exception_code' => null,
                    'resolution_notes' => $m->notes, 'resolved_by' => $checker->id, 'resolved_at' => now(),
                ]);
                $this->queue->close($item, 'MANUALLY_MATCHED', $checker);
                $this->reconciliation->recount($item->import);
            }
            DB::table('reconciliation_manual_matches')->where('id', $m->id)->update(['status' => $status, 'decided_by' => $checker->id, 'decided_at' => now(), 'decision_note' => $note, 'updated_at' => now()]);
            $event = $approve ? 'reconciliation.manual_match.approved' : 'reconciliation.manual_match.rejected';
            $this->audit->record($event, 'reconciliation_item', $item->id, ['manual_match_id' => $m->id, 'payment_intent_id' => $m->matched_id], $note);
            $this->outbox->record($event, 'reconciliation_item', $item->id, ['manual_match_id' => $m->id, 'payment_intent_id' => $m->matched_id]);

            return DB::table('reconciliation_manual_matches')->find($m->id);
        });
    }

    private function scoped(string $tenantId)
    {
        return ReconciliationItem::whereHas('import', fn ($q) => $q->where('tenant_id', $tenantId));
    }
}
