<?php

declare(strict_types=1);

namespace App\Application\Settlements;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Finance\Obligations\ObligationService;
use App\Application\FinancialDistribution\CarrierSettlementService;
use App\Application\FinancialDistribution\DistributionEventWriter;
use App\Application\Ledger\FinancialPostingService;
use App\Application\Payments\ExecutionModes\PaymentCollectionModes;
use App\Models\SettlementBatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-STL-001 (WF-068/069, SSR FIN-019) + REQ-DUP-011 — the one settlement service.
 *
 * Two lifecycles share settlement_batches, told apart by calculation_basis:
 *   POLICY       legacy Wave6 batches (CarrierSettlementService: DRAFT→APPROVED→SUBMITTED→PAID|FAILED→REVERSED);
 *                this service delegates to it so the carrier-settlements/* routes keep working unchanged.
 *   OBLIGATIONS  broker–insurer settlement calculated from the obligation ledger:
 *                DRAFT→CALCULATED→REVIEW→APPROVED→PROCESSING→SETTLED→RECONCILED (+ CANCELLED, REVIEW→DRAFT on rejection,
 *                PROCESSING→APPROVED on a failed transfer).
 *
 * Calculation: for every carrier policy, premium collected as of period_end is read from the policy's RECEIVABLE
 * obligation history (SETTLEMENT − UNSETTLEMENT events). Only collections whose frozen collection_semantics require
 * remittance to the carrier (Batch 9-4) are remittable — INSURER_COLLECTION money is already with the carrier.
 * Net due = collected − commission retained (accrued − clawed back, capped at collected), minus what earlier live
 * OBLIGATIONS batches already included. Each line opens a carrier PAYABLE obligation that settle() settles.
 */
final class SettlementService
{
    public const BASIS_POLICY = 'POLICY';

    public const BASIS_OBLIGATIONS = 'OBLIGATIONS';

    public const STATUSES = ['DRAFT', 'CALCULATED', 'REVIEW', 'APPROVED', 'PROCESSING', 'SETTLED', 'RECONCILED', 'CANCELLED'];

    /** from => allowed targets (OBLIGATIONS basis). */
    public const TRANSITIONS = [
        'DRAFT' => ['CALCULATED', 'CANCELLED'],
        'CALCULATED' => ['CALCULATED', 'REVIEW', 'CANCELLED'],
        'REVIEW' => ['APPROVED', 'DRAFT', 'CANCELLED'],
        'APPROVED' => ['PROCESSING'],
        'PROCESSING' => ['SETTLED', 'APPROVED'],
        'SETTLED' => ['RECONCILED'],
        'RECONCILED' => [],
        'CANCELLED' => [],
    ];

    private const LIVE = ['DRAFT', 'CALCULATED', 'REVIEW', 'APPROVED', 'PROCESSING', 'SETTLED', 'RECONCILED'];

    public function __construct(
        private readonly CarrierSettlementService $legacy,
        private readonly ObligationService $obligations,
        private readonly FinancialPostingService $posting,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
        private readonly DistributionEventWriter $events,
    ) {
        // App\Domain\Ledger\JournalLine is declared inside Journal.php (not PSR-4 loadable on its own) and
        // LedgerService::post instantiates it before Journal; load the file first so posting works.
        class_exists(\App\Domain\Ledger\Journal::class);
    }

    // --- reads (REQ-DUP-011: every settlement read goes through here) --------------------------------

    public function find(string $tenantId, string $id): SettlementBatch
    {
        $b = Str::isUuid($id) ? SettlementBatch::where('tenant_id', $tenantId)->find($id) : null;
        abort_unless($b, 404);

        return $b;
    }

    /** @return array{batch:object, items:\Illuminate\Support\Collection, approvals:\Illuminate\Support\Collection, lifecycle:array} */
    public function present(SettlementBatch $b): array
    {
        $basis = $b->calculation_basis ?? self::BASIS_POLICY;

        return [
            'batch' => DB::table('settlement_batches')->where('id', $b->id)->first(),
            'items' => DB::table('settlement_items')->where('settlement_batch_id', $b->id)->orderBy('created_at')->orderBy('id')->get(),
            'approvals' => DB::table('settlement_approvals')->where('settlement_batch_id', $b->id)->orderBy('decided_at')->get(),
            'lifecycle' => [
                'basis' => $basis,
                'status' => $b->status,
                'next' => $basis === self::BASIS_OBLIGATIONS ? array_values(array_diff(self::TRANSITIONS[$b->status] ?? [], [$b->status])) : [],
            ],
        ];
    }

    // --- legacy-compatible entry points used by carrier-settlements/* ---------------------------------

    public function prepare(array $d, User $actor): SettlementBatch
    {
        return $this->legacy->prepare($d, $actor);
    }

    public function approveAny(SettlementBatch $b, User $actor, ?string $notes = null): SettlementBatch
    {
        return $this->isLedger($b) ? $this->approve($b, $actor, $notes) : $this->legacy->approve($b, $actor, $notes);
    }

    public function submitAny(SettlementBatch $b, User $actor): SettlementBatch
    {
        return $this->isLedger($b) ? $this->process($b, $actor) : $this->legacy->submit($b, $actor);
    }

    public function paidAny(SettlementBatch $b, string $reference, User $actor): SettlementBatch
    {
        return $this->isLedger($b) ? $this->settle($b, $reference, $actor) : $this->legacy->markPaid($b, $reference, $actor);
    }

    public function failAny(SettlementBatch $b, string $reason, User $actor): SettlementBatch
    {
        return $this->isLedger($b) ? $this->failProcessing($b, $reason, $actor) : $this->legacy->fail($b, $reason, $actor);
    }

    public function reverseAny(SettlementBatch $b, string $reason, User $actor): SettlementBatch
    {
        if ($this->isLedger($b)) {
            $this->refuse('SETTLEMENT_REVERSE_UNSUPPORTED', 'Ledger-calculated settlements are corrected by a new batch, not reversed.');
        }

        return $this->legacy->reverse($b, $reason, $actor);
    }

    // --- OBLIGATIONS lifecycle -------------------------------------------------------------------------

    /** @param array{tenant_id:string, carrier_id:string, partner_id?:?string, period_start:string, period_end:string, currency:string, idempotency_key:string} $d */
    public function draft(array $d, User $actor): SettlementBatch
    {
        return DB::transaction(function () use ($d, $actor): SettlementBatch {
            if ($e = SettlementBatch::where('tenant_id', $d['tenant_id'])->where('idempotency_key', $d['idempotency_key'])->first()) {
                return $e;
            }
            $b = SettlementBatch::create([
                'tenant_id' => $d['tenant_id'], 'carrier_id' => $d['carrier_id'], 'partner_id' => $d['partner_id'] ?? null,
                'period_start' => $d['period_start'], 'period_end' => $d['period_end'], 'currency' => $d['currency'],
                'idempotency_key' => $d['idempotency_key'], 'calculation_basis' => self::BASIS_OBLIGATIONS,
                'settlement_number' => 'STL-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)),
                'net_amount_minor' => 0, 'status' => 'DRAFT', 'prepared_by' => $actor->id, 'correlation_id' => (string) Str::uuid(),
            ]);
            $this->events->write($b->tenant_id, 'CARRIER_SETTLEMENT', $b->id, null, 'DRAFT', 'DRAFTED', $actor);
            $this->audit->record('settlement.drafted', 'settlement_batch', $b->id, ['carrier_id' => $b->carrier_id, 'currency' => $b->currency]);
            $this->outbox->record('settlement.drafted', 'settlement_batch', $b->id, ['settlement_id' => $b->id, 'carrier_id' => $b->carrier_id]);

            return $b;
        });
    }

    /** DRAFT|CALCULATED → CALCULATED. Recalculation cancels the previous run's payables first. */
    public function calculate(SettlementBatch $b, User $actor): SettlementBatch
    {
        return DB::transaction(function () use ($b, $actor): SettlementBatch {
            $b = $this->lockLedger($b, 'CALCULATED');
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['settlement-calc:'.$b->tenant_id.':'.$b->carrier_id.':'.$b->currency]);
            $this->discardLines($b, $actor, 'RECALCULATED');
            $version = (int) (($b->calculation_summary['version'] ?? 0) + 1);
            [$lines, $summary] = $this->lines($b);
            $net = 0;
            foreach ($lines as $l) {
                $o = $this->obligations->create([
                    'tenant_id' => $b->tenant_id, 'kind' => 'PAYABLE', 'type' => 'PREMIUM',
                    'source_type' => 'settlement_batch', 'source_id' => $b->id, 'source_reference' => "v{$version}:{$l['policy_id']}",
                    'policy_id' => $l['policy_id'], 'currency' => $b->currency, 'amount_minor' => $l['net'],
                    'due_at' => CarbonImmutable::parse($b->period_end)->endOfDay(),
                    'debtor_type' => $l['debtor_type'], 'debtor_id' => $l['debtor_id'],
                    'creditor_type' => 'carrier', 'creditor_id' => $b->carrier_id,
                    'description' => "Premium remittance {$b->settlement_number}",
                    'metadata' => ['collection_mode' => $l['mode'], 'gross_minor' => $l['gross'], 'commission_minor' => $l['commission']],
                ], $actor->id);
                DB::table('settlement_items')->insert([
                    'id' => (string) Str::uuid(), 'settlement_batch_id' => $b->id, 'policy_id' => $l['policy_id'], 'payment_intent_id' => $l['payment_intent_id'],
                    'gross_premium_minor' => $l['gross'], 'commission_minor' => $l['commission'], 'tax_minor' => 0, 'adjustment_minor' => 0,
                    'net_due_minor' => $l['net'], 'currency' => $b->currency, 'status' => 'INCLUDED',
                    'financial_obligation_id' => $o->id, 'collection_mode' => $l['mode'], 'created_at' => now(), 'updated_at' => now(),
                ]);
                $net += $l['net'];
            }
            $summary['version'] = $version;
            $hash = hash('sha256', json_encode(array_map(fn ($l) => [$l['policy_id'], $l['gross'], $l['commission'], $l['net']], $lines), JSON_THROW_ON_ERROR));

            return $this->transition($b, 'CALCULATED', 'CALCULATED', $actor, [
                'net_amount_minor' => $net, 'calculation_summary' => $summary, 'calculated_at' => now(), 'content_hash' => $hash,
            ], 'settlement.calculated', ['net_amount_minor' => $net, 'lines' => count($lines)]);
        });
    }

    public function submitForReview(SettlementBatch $b, User $actor): SettlementBatch
    {
        return DB::transaction(function () use ($b, $actor): SettlementBatch {
            $b = $this->lockLedger($b, 'REVIEW');
            if ((int) $b->net_amount_minor <= 0) {
                $this->refuse('SETTLEMENT_EMPTY', 'Nothing is due to the carrier for this period.');
            }

            return $this->transition($b, 'REVIEW', 'SUBMITTED_FOR_REVIEW', $actor, [], 'settlement.review_requested');
        });
    }

    /** REVIEW → APPROVED; maker-checker; posts settlement.approved. */
    public function approve(SettlementBatch $b, User $actor, ?string $notes = null): SettlementBatch
    {
        return DB::transaction(function () use ($b, $actor, $notes): SettlementBatch {
            $b = $this->lockLedger($b, 'APPROVED');
            if ($b->prepared_by === $actor->id) {
                $this->refuse('SETTLEMENT_SELF_APPROVAL', 'The preparer cannot approve their own settlement.');
            }
            DB::table('settlement_approvals')->insert([
                'id' => (string) Str::uuid(), 'settlement_batch_id' => $b->id, 'stage' => 'FINANCE_APPROVAL', 'decision' => 'APPROVED',
                'actor_id' => $actor->id, 'notes' => $notes ?: __('wave6.settlement_approved_default_note'), 'decided_at' => now(),
            ]);
            $journal = $this->posting->post($b->tenant_id, 'settlement.approved', $b->id, (int) $b->net_amount_minor, $b->currency, $b->correlation_id ?? $b->id);

            return $this->transition($b, 'APPROVED', 'APPROVED', $actor, ['approved_by' => $actor->id, 'approved_at' => now(), 'reviewed_by' => $actor->id, 'reviewed_at' => now()],
                'settlement.approved', ['net_amount_minor' => (int) $b->net_amount_minor, 'currency' => $b->currency, 'journal_id' => $journal]);
        });
    }

    /** REVIEW → DRAFT: the reviewer sends it back; the run's payables are cancelled. */
    public function reject(SettlementBatch $b, User $actor, string $reason): SettlementBatch
    {
        return DB::transaction(function () use ($b, $actor, $reason): SettlementBatch {
            $b = $this->lockLedger($b, 'DRAFT');
            $this->discardLines($b, $actor, 'REVIEW_REJECTED');

            return $this->transition($b, 'DRAFT', 'REVIEW_REJECTED', $actor, ['net_amount_minor' => 0, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'failure_reason' => $reason],
                'settlement.rejected', ['reason' => $reason]);
        });
    }

    public function cancel(SettlementBatch $b, User $actor, string $reason): SettlementBatch
    {
        return DB::transaction(function () use ($b, $actor, $reason): SettlementBatch {
            $b = $this->lockLedger($b, 'CANCELLED');
            $this->discardLines($b, $actor, 'CANCELLED');

            return $this->transition($b, 'CANCELLED', 'CANCELLED', $actor, ['failure_reason' => $reason], 'settlement.cancelled', ['reason' => $reason]);
        });
    }

    public function process(SettlementBatch $b, User $actor): SettlementBatch
    {
        return DB::transaction(fn (): SettlementBatch => $this->transition($this->lockLedger($b, 'PROCESSING'), 'PROCESSING', 'PAYMENT_SUBMITTED', $actor,
            ['processing_at' => now(), 'submitted_at' => now(), 'failure_reason' => null], 'settlement.processing'));
    }

    public function failProcessing(SettlementBatch $b, string $reason, User $actor): SettlementBatch
    {
        return DB::transaction(fn (): SettlementBatch => $this->transition($this->lockLedger($b, 'APPROVED'), 'APPROVED', 'PAYMENT_FAILED', $actor,
            ['failure_reason' => $reason, 'processing_at' => null], 'settlement.processing_failed', ['reason' => $reason]));
    }

    /** PROCESSING → SETTLED: settles every line's carrier PAYABLE obligation and posts settlement.settled. */
    public function settle(SettlementBatch $b, string $bankReference, User $actor): SettlementBatch
    {
        return DB::transaction(function () use ($b, $bankReference, $actor): SettlementBatch {
            $b = $this->lockLedger($b, 'SETTLED');
            $ref = 'settlement:'.$b->id;
            DB::table('settlement_items')->where('settlement_batch_id', $b->id)->where('status', 'INCLUDED')->orderBy('id')->get()
                ->each(function ($i) use ($ref, $actor): void {
                    if ($i->financial_obligation_id) {
                        $this->obligations->settle($i->financial_obligation_id, (int) $i->net_due_minor, $ref, $actor->id);
                    }
                    DB::table('settlement_items')->where('id', $i->id)->update(['status' => 'SETTLED', 'updated_at' => now()]);
                });
            $journal = $this->posting->post($b->tenant_id, 'settlement.settled', $b->id, (int) $b->net_amount_minor, $b->currency, $b->correlation_id ?? $b->id);

            return $this->transition($b, 'SETTLED', 'PAYMENT_CONFIRMED', $actor, ['settled_at' => now(), 'paid_at' => now(), 'bank_reference' => $bankReference],
                'settlement.settled', ['net_amount_minor' => (int) $b->net_amount_minor, 'currency' => $b->currency, 'bank_reference' => $bankReference, 'journal_id' => $journal]);
        });
    }

    /** SETTLED → RECONCILED once every payable is fully settled and the bank line is matched. */
    public function reconcile(SettlementBatch $b, string $reference, User $actor): SettlementBatch
    {
        return DB::transaction(function () use ($b, $reference, $actor): SettlementBatch {
            $b = $this->lockLedger($b, 'RECONCILED');
            $ids = DB::table('settlement_items')->where('settlement_batch_id', $b->id)->whereNotNull('financial_obligation_id')->pluck('financial_obligation_id');
            if (DB::table('financial_obligations')->whereIn('id', $ids)->where('status', '!=', 'SETTLED')->exists()) {
                $this->refuse('SETTLEMENT_UNRECONCILED', 'Some carrier payables of this settlement are not fully settled.');
            }

            return $this->transition($b, 'RECONCILED', 'RECONCILED', $actor, ['reconciled_at' => now(), 'reconciliation_reference' => $reference],
                'settlement.reconciled', ['reference' => $reference]);
        });
    }

    // --- internals -------------------------------------------------------------------------------------

    /** @return array{0:list<array>, 1:array} */
    private function lines(SettlementBatch $b): array
    {
        $asOf = CarbonImmutable::parse($b->period_end)->endOfDay();
        $policies = DB::table('policies')->where('tenant_id', $b->tenant_id)->where('carrier_id', $b->carrier_id)->where('currency', $b->currency)
            ->whereNotNull('payment_intent_id')->whereRaw('COALESCE(issued_at, created_at) <= ?', [$asOf])
            ->when($b->partner_id, fn ($q, $p) => $q->whereIn('id', DB::table('commission_accruals')->where('partner_id', $p)->select('policy_id')))
            ->orderBy('id')->get(['id', 'payment_intent_id']);
        $summary = ['by_collection_mode' => [], 'excluded_carrier_collected_minor' => 0, 'policies_considered' => $policies->count(), 'as_of' => $asOf->toIso8601String()];
        $lines = [];
        foreach ($policies as $p) {
            $collected = $this->collected($p->id, $asOf);
            if ($collected <= 0) {
                continue;
            }
            $sem = $this->semantics($p->payment_intent_id);
            if (! $sem['requires_remittance']) {
                $summary['excluded_carrier_collected_minor'] += $collected;

                continue;
            }
            $accruals = DB::table('commission_accruals')->where('policy_id', $p->id)->where('currency', $b->currency)->whereNotIn('status', ['CANCELLED', 'REVERSED'])
                ->get(['partner_id', 'amount_minor', 'clawed_back_minor']);
            $commission = min($collected, (int) $accruals->sum(fn ($a) => max(0, (int) $a->amount_minor - (int) $a->clawed_back_minor)));
            $prior = DB::table('settlement_items as i')->join('settlement_batches as sb', 'sb.id', '=', 'i.settlement_batch_id')
                ->where('sb.calculation_basis', self::BASIS_OBLIGATIONS)->whereIn('sb.status', self::LIVE)->where('sb.id', '!=', $b->id)
                ->where('i.policy_id', $p->id)->selectRaw('COALESCE(SUM(i.gross_premium_minor),0) AS g, COALESCE(SUM(i.commission_minor),0) AS c')->first();
            $gross = $collected - (int) $prior->g;
            $comm = $commission - (int) $prior->c;
            $net = $gross - $comm;
            if ($net <= 0) {
                continue;
            }
            $broker = $sem['funds_holder'] === 'BROKER' ? ($accruals->firstWhere('partner_id', '!=', null)->partner_id ?? $b->partner_id) : null;
            $lines[] = ['policy_id' => $p->id, 'payment_intent_id' => $p->payment_intent_id, 'gross' => $gross, 'commission' => $comm, 'net' => $net, 'mode' => $sem['mode'],
                'debtor_type' => $broker ? 'partner' : strtolower($sem['funds_holder']), 'debtor_id' => $broker];
            $m = &$summary['by_collection_mode'][$sem['mode']];
            $m ??= ['lines' => 0, 'gross_minor' => 0, 'commission_minor' => 0, 'net_minor' => 0];
            $m['lines']++;
            $m['gross_minor'] += $gross;
            $m['commission_minor'] += $comm;
            $m['net_minor'] += $net;
            unset($m);
        }

        return [$lines, $summary];
    }

    /** Premium the policy's RECEIVABLE obligations had actually collected by $asOf, from the obligation event history. */
    private function collected(string $policyId, CarbonImmutable $asOf): int
    {
        $r = DB::table('financial_obligation_events as e')->join('financial_obligations as o', 'o.id', '=', 'e.financial_obligation_id')
            ->where('o.policy_id', $policyId)->where('o.kind', 'RECEIVABLE')->whereIn('o.type', ['PREMIUM', 'INSTALMENT'])
            ->whereIn('e.event_type', ['SETTLEMENT', 'UNSETTLEMENT'])->where('e.occurred_at', '<=', $asOf)
            ->selectRaw("COALESCE(SUM(CASE WHEN e.event_type = 'SETTLEMENT' THEN e.amount_minor ELSE -e.amount_minor END),0) AS v")->first();

        return (int) $r->v;
    }

    /** Frozen collection semantics of the paying intent (Batch 9-4); no recorded mode = the platform PSP default. */
    private function semantics(string $paymentIntentId): array
    {
        $pi = DB::table('payment_intents')->where('id', $paymentIntentId)->first(['collection_mode', 'collection_semantics']);
        $frozen = $pi && $pi->collection_semantics ? json_decode((string) $pi->collection_semantics, true) : null;
        if (is_array($frozen) && isset($frozen['mode'], $frozen['requires_remittance'], $frozen['funds_holder'])) {
            return $frozen;
        }
        $mode = ($pi && $pi->collection_mode ? PaymentCollectionModes::normalize($pi->collection_mode) : null) ?? 'MOBILE_MONEY';

        return PaymentCollectionModes::semantics($mode);
    }

    private function discardLines(SettlementBatch $b, User $actor, string $reason): void
    {
        DB::table('settlement_items')->where('settlement_batch_id', $b->id)->whereNotNull('financial_obligation_id')->pluck('financial_obligation_id')
            ->each(function (string $id) use ($actor, $reason): void {
                if (in_array(DB::table('financial_obligations')->where('id', $id)->value('status'), ObligationService::OPEN_STATUSES, true)) {
                    $this->obligations->cancel($id, 'settlement '.strtolower($reason), $actor->id);
                }
            });
        DB::table('settlement_items')->where('settlement_batch_id', $b->id)->delete();
    }

    private function isLedger(SettlementBatch $b): bool
    {
        return ($b->calculation_basis ?? self::BASIS_POLICY) === self::BASIS_OBLIGATIONS;
    }

    private function lockLedger(SettlementBatch $b, string $to): SettlementBatch
    {
        $b = SettlementBatch::lockForUpdate()->findOrFail($b->id);
        if (! $this->isLedger($b)) {
            $this->refuse('SETTLEMENT_BASIS', 'This settlement follows the policy-based lifecycle.');
        }
        if (! in_array($to, self::TRANSITIONS[$b->status] ?? [], true)) {
            $this->refuse('SETTLEMENT_INVALID_TRANSITION', "Cannot move a settlement from {$b->status} to {$to}.");
        }

        return $b;
    }

    private function transition(SettlementBatch $b, string $to, string $reason, User $actor, array $attrs, string $event, array $payload = []): SettlementBatch
    {
        $from = $b->status;
        $b->update(['status' => $to] + $attrs);
        $this->events->write($b->tenant_id, 'CARRIER_SETTLEMENT', $b->id, $from, $to, $reason, $actor);
        $this->audit->record($event, 'settlement_batch', $b->id, ['from' => $from, 'to' => $to] + $payload);
        $this->outbox->record($event, 'settlement_batch', $b->id, ['settlement_id' => $b->id, 'carrier_id' => $b->carrier_id, 'from' => $from, 'to' => $to] + $payload);

        return $b->refresh();
    }

    private function refuse(string $code, string $message): never
    {
        throw ValidationException::withMessages(['settlement' => "{$code}: {$message}"]);
    }
}
