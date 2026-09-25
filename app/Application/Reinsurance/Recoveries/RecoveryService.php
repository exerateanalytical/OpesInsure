<?php

declare(strict_types=1);

namespace App\Application\Reinsurance\Recoveries;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Finance\Obligations\ObligationService;
use App\Application\Ledger\FinancialPostingService;
use App\Application\Reinsurance\CessionService;
use App\Application\Reinsurance\TreatyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-REI-004: reinsurance recoveries on claims.
 *
 * estimate() reads the claim (claims.current_reserve_minor = outstanding reserve; claim_payments PAID and
 * claim_settlements PAID without a linked payment = paid) and the policy's CALCULATED cessions (CessionService),
 * plus facultative placements when that table exists, and records one recovery per source split by reinsurer.
 * Amounts refresh while ESTIMATED / NOTIFIED / DISPUTED (unbilled); from AGREED they are frozen.
 *
 * Lifecycle: ESTIMATED -> NOTIFIED -> AGREED -> BILLED -> SETTLED -> CLOSED, with DISPUTED from NOTIFIED/AGREED/BILLED.
 * Billing raises one RECEIVABLE financial_obligation per reinsurer (type CLAIM, source reinsurance_recovery) and posts
 * reinsurance.recovery.billed; each receipt settles the reinsurer's obligation and posts reinsurance.recovery.settled.
 * XL reinstatement premium is raised on billing as a PAYABLE obligation per reinsurer (type OTHER).
 * Large-loss: when the claim's gross incurred reaches reinsurance_treaties.large_loss_threshold_minor (null = never)
 * the recovery is flagged, notified once and emits reinsurance.recovery.large_loss_notified.
 */
final class RecoveryService
{
    public const TRANSITIONS = [
        'ESTIMATED' => ['NOTIFIED', 'CLOSED'],
        'NOTIFIED' => ['AGREED', 'DISPUTED', 'CLOSED'],
        'AGREED' => ['BILLED', 'DISPUTED'],
        'BILLED' => ['SETTLED', 'DISPUTED'],
        'DISPUTED' => ['AGREED', 'BILLED', 'CLOSED'],
        'SETTLED' => ['CLOSED'],
        'CLOSED' => [],
    ];

    private const REFRESHABLE = ['ESTIMATED', 'NOTIFIED', 'DISPUTED'];

    /** Tables E6 (facultative) may create; the first one present with the needed columns is used. */
    private const FACULTATIVE_TABLES = ['reinsurance_facultative_cessions', 'facultative_cessions', 'reinsurance_facultative_placements'];

    public function __construct(
        private readonly CessionService $cessions,
        private readonly TreatyService $treaties,
        private readonly RecoveryCalculator $calculator,
        private readonly ObligationService $obligations,
        private readonly FinancialPostingService $posting,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** Compute recoveries without persisting. */
    public function preview(string $tenantId, string $claimId): array
    {
        [$claim, $policy, $basis] = $this->claimBasis($tenantId, $claimId);
        $sources = $this->sources($tenantId, $policy);

        return ['claim_id' => $claim->id, 'currency' => $policy->currency] + $basis + [
            'recoveries' => array_map(fn ($r) => $this->strip($r), $this->calculator->calculate($basis['gross_incurred_minor'], $sources, $this->stopLoss($claimId))),
        ];
    }

    /** Estimate / refresh the claim's recoveries. Idempotent: unchanged amounts write nothing. */
    public function estimate(string $tenantId, string $claimId, ?string $actorId = null): array
    {
        return DB::transaction(function () use ($tenantId, $claimId, $actorId) {
            [$claim, $policy, $basis] = $this->claimBasis($tenantId, $claimId, lock: true);
            $sources = $this->sources($tenantId, $policy);
            $incurred = $this->calculator->calculate($basis['gross_incurred_minor'], $sources, $this->stopLoss($claimId));
            $paid = collect($this->calculator->calculate($basis['gross_paid_minor'], $sources, $this->stopLoss($claimId)))->keyBy('source_key');

            foreach ($incurred as $r) {
                $row = DB::table('reinsurance_recoveries')->where('claim_id', $claimId)->where('source_key', $r['source_key'])->lockForUpdate()->first();
                $values = ['gross_incurred_minor' => $basis['gross_incurred_minor'], 'gross_paid_minor' => $basis['gross_paid_minor'], 'subject_loss_minor' => $r['subject_loss_minor'],
                    'recoverable_minor' => $r['recoverable_minor'], 'recoverable_paid_minor' => (int) ($paid[$r['source_key']]['recoverable_minor'] ?? 0),
                    'reinstatement_premium_minor' => $r['reinstatement_premium_minor'], 'layers' => json_encode($r['recovery_layers']),
                    'basis' => json_encode(['reserve_minor' => $basis['outstanding_reserve_minor'], 'paid_minor' => $basis['gross_paid_minor'], 'ceded_percent' => $r['ceded_percent'] ?? null])];
                if (! $row) {
                    $id = (string) Str::uuid();
                    DB::table('reinsurance_recoveries')->insert($values + ['id' => $id, 'tenant_id' => $tenantId, 'claim_id' => $claimId, 'policy_id' => $policy->id,
                        'source_type' => $r['source_type'], 'source_key' => $r['source_key'], 'treaty_id' => $r['treaty_id'] ?? null, 'treaty_version_id' => $r['treaty_version_id'] ?? null,
                        'cession_id' => $r['cession_id'] ?? null, 'treaty_type' => $r['treaty_type'], 'currency' => $policy->currency, 'status' => 'ESTIMATED',
                        'created_at' => now(), 'updated_at' => now()]);
                    $this->writeShares($id, $r);
                    $this->history($id, 'ESTIMATED', null, 'ESTIMATED', $r['recoverable_minor'], null, null, $actorId);
                    $this->outbox->record('reinsurance.recovery.estimated', 'reinsurance_recovery', $id, ['tenant_id' => $tenantId, 'claim_id' => $claimId,
                        'treaty_type' => $r['treaty_type'], 'recoverable_minor' => $r['recoverable_minor'], 'currency' => $policy->currency]);
                } elseif (in_array($row->status, self::REFRESHABLE, true) && (int) $row->billed_minor === 0 && $this->changed($row, $values)) {
                    DB::table('reinsurance_recoveries')->where('id', $row->id)->update($values + ['version' => (int) $row->version + 1, 'updated_at' => now()]);
                    $this->writeShares($row->id, $r);
                    $this->history($row->id, 'REESTIMATED', $row->status, $row->status, $r['recoverable_minor'], null, null, $actorId, ['previous_minor' => (int) $row->recoverable_minor]);
                    $this->outbox->record('reinsurance.recovery.estimated', 'reinsurance_recovery', $row->id, ['tenant_id' => $tenantId, 'claim_id' => $claimId,
                        'treaty_type' => $r['treaty_type'], 'recoverable_minor' => $r['recoverable_minor'], 'previous_minor' => (int) $row->recoverable_minor, 'currency' => $policy->currency]);
                }
            }
            $this->largeLoss($tenantId, $claimId, $basis['gross_incurred_minor'], $actorId);
            $this->audit->record('reinsurance.recovery.estimated', 'claim', $claimId, ['gross_incurred_minor' => $basis['gross_incurred_minor'], 'sources' => count($incurred)]);

            return $this->forClaim($tenantId, $claimId);
        });
    }

    public function forClaim(string $tenantId, string $claimId): array
    {
        [$claim, $policy, $basis] = $this->claimBasis($tenantId, $claimId);
        $rows = DB::table('reinsurance_recoveries')->where('tenant_id', $tenantId)->where('claim_id', $claimId)->orderBy('created_at')->pluck('id')
            ->map(fn ($id) => $this->show($tenantId, $id))->all();

        return ['claim_id' => $claim->id, 'policy_id' => $policy->id, 'currency' => $policy->currency] + $basis + [
            'recoverable_minor' => array_sum(array_column($rows, 'recoverable_minor')), 'recoveries' => $rows];
    }

    public function show(string $tenantId, string $id): array
    {
        $r = $this->find($tenantId, $id);
        $a = (array) $r;
        foreach (['layers', 'basis'] as $k) {
            $a[$k] = json_decode((string) $a[$k], true);
        }
        $a['shares'] = DB::table('reinsurance_recovery_shares')->where('recovery_id', $id)->orderBy('reinsurer_id')->get()->map(fn ($s) => (array) $s)->all();
        $a['events'] = DB::table('reinsurance_recovery_events')->where('recovery_id', $id)->orderBy('occurred_at')->get()->map(fn ($e) => (array) $e)->all();

        return $a;
    }

    public function notify(string $tenantId, string $id, ?string $actorId, ?string $note = null): array
    {
        return $this->transition($tenantId, $id, 'NOTIFIED', $actorId, function ($r) {
            return ['notified_at' => now()];
        }, 'reinsurance.recovery.notified', ['note' => $note]);
    }

    /** Agree with reinsurers; amount defaults to the estimate and may not exceed it. */
    public function agree(string $tenantId, string $id, ?int $amount, ?string $actorId, ?string $note = null): array
    {
        return $this->transition($tenantId, $id, 'AGREED', $actorId, function ($r) use ($amount, $actorId) {
            if ((int) $r->billed_minor > 0) {
                $this->fail('status', 'A billed recovery resumes billing, it cannot be re-agreed.');
            }
            $agreed = $amount ?? (int) $r->recoverable_minor;
            if ($agreed <= 0 || $agreed > (int) $r->recoverable_minor) {
                $this->fail('amount_minor', 'Agreed amount must be positive and not exceed the recoverable estimate.');
            }

            return ['agreed_minor' => $agreed, 'agreed_at' => now(), 'agreed_by' => $actorId, 'dispute_reason' => null];
        }, 'reinsurance.recovery.agreed', ['note' => $note]);
    }

    public function bill(string $tenantId, string $id, string $dueAt, ?string $actorId): array
    {
        return DB::transaction(function () use ($tenantId, $id, $dueAt, $actorId) {
            $r = $this->find($tenantId, $id, lock: true);
            if ($r->status === 'DISPUTED' && (int) $r->billed_minor > 0) {
                return $this->transition($tenantId, $id, 'BILLED', $actorId, fn () => ['dispute_reason' => null], 'reinsurance.recovery.billed', ['resumed' => true]);
            }
            $amount = (int) $r->agreed_minor;

            return $this->transition($tenantId, $id, 'BILLED', $actorId, function ($r) use ($amount, $dueAt, $actorId, $tenantId) {
                $shares = DB::table('reinsurance_recovery_shares')->where('recovery_id', $r->id)->orderBy('reinsurer_id')->get()->values();
                $split = RecoveryCalculator::split($shares->map(fn ($s) => (array) $s)->all(), $amount);
                foreach ($shares as $i => $s) {
                    $update = ['billed_minor' => $split[$i], 'updated_at' => now()];
                    if ($split[$i] > 0) {
                        $o = $this->obligations->create(['tenant_id' => $tenantId, 'kind' => 'RECEIVABLE', 'type' => 'CLAIM', 'debtor_type' => 'reinsurer', 'debtor_id' => $s->reinsurer_id,
                            'source_type' => 'reinsurance_recovery', 'source_id' => $r->id, 'source_reference' => $s->reinsurer_id, 'policy_id' => $r->policy_id,
                            'currency' => $r->currency, 'amount_minor' => $split[$i], 'due_at' => $dueAt, 'description' => 'Reinsurance recovery',
                            'metadata' => ['claim_id' => $r->claim_id, 'treaty_version_id' => $r->treaty_version_id]], $actorId);
                        $update['financial_obligation_id'] = $o->id;
                    }
                    if ((int) $s->reinstatement_premium_minor > 0) {
                        $p = $this->obligations->create(['tenant_id' => $tenantId, 'kind' => 'PAYABLE', 'type' => 'OTHER', 'creditor_type' => 'reinsurer', 'creditor_id' => $s->reinsurer_id,
                            'source_type' => 'reinsurance_reinstatement', 'source_id' => $r->id, 'source_reference' => $s->reinsurer_id, 'policy_id' => $r->policy_id,
                            'currency' => $r->currency, 'amount_minor' => (int) $s->reinstatement_premium_minor, 'due_at' => $dueAt, 'description' => 'XL reinstatement premium',
                            'metadata' => ['claim_id' => $r->claim_id, 'treaty_version_id' => $r->treaty_version_id]], $actorId);
                        $update['reinstatement_obligation_id'] = $p->id;
                    }
                    DB::table('reinsurance_recovery_shares')->where('id', $s->id)->update($update);
                }
                $journal = $this->posting->post($tenantId, 'reinsurance.recovery.billed', $r->id, $amount, $r->currency, 'rei-recovery:'.$r->id);

                return ['billed_minor' => $amount, 'billed_at' => now(), 'billed_journal_id' => $journal];
            }, 'reinsurance.recovery.billed', ['amount_minor' => $amount]);
        });
    }

    /** Cash from one reinsurer: settles its obligation, posts reinsurance.recovery.settled. Idempotent per (reinsurer, reference). */
    public function receive(string $tenantId, string $id, string $reinsurerId, int $amount, string $reference, ?string $actorId): array
    {
        return DB::transaction(function () use ($tenantId, $id, $reinsurerId, $amount, $reference, $actorId) {
            $r = $this->find($tenantId, $id, lock: true);
            $seen = DB::table('reinsurance_recovery_events')->where(['recovery_id' => $id, 'event_type' => 'RECEIPT', 'reinsurer_id' => $reinsurerId, 'reference' => $reference])->exists();
            if ($seen) {
                return $this->show($tenantId, $id);
            }
            if ($r->status !== 'BILLED') {
                $this->fail('status', "Receipts apply to BILLED recoveries (status {$r->status}).");
            }
            $share = DB::table('reinsurance_recovery_shares')->where('recovery_id', $id)->where('reinsurer_id', $reinsurerId)->lockForUpdate()->first();
            if (! $share || ! $share->financial_obligation_id) {
                $this->fail('reinsurer_id', 'The reinsurer has no billed share on this recovery.');
            }
            $left = (int) $share->billed_minor - (int) $share->settled_minor;
            if ($amount <= 0 || $amount > $left) {
                $this->fail('amount_minor', "Receipt must be positive and not exceed the outstanding {$left}.");
            }
            $this->obligations->settle($share->financial_obligation_id, $amount, $reference, $actorId);
            DB::table('reinsurance_recovery_shares')->where('id', $share->id)->update(['settled_minor' => (int) $share->settled_minor + $amount, 'updated_at' => now()]);
            $eventId = $this->history($id, 'RECEIPT', 'BILLED', 'BILLED', $amount, $reinsurerId, $reference, $actorId);
            $journal = $this->posting->post($tenantId, 'reinsurance.recovery.settled', $eventId, $amount, $r->currency, 'rei-recovery:'.$id);
            DB::table('reinsurance_recovery_events')->where('id', $eventId)->update(['journal_id' => $journal]);
            $settled = (int) $r->settled_minor + $amount;
            DB::table('reinsurance_recoveries')->where('id', $id)->update(['settled_minor' => $settled, 'version' => (int) $r->version + 1, 'updated_at' => now()]);
            $this->outbox->record('reinsurance.recovery.settled', 'reinsurance_recovery', $id, ['tenant_id' => $tenantId, 'claim_id' => $r->claim_id, 'reinsurer_id' => $reinsurerId,
                'amount_minor' => $amount, 'settled_minor' => $settled, 'billed_minor' => (int) $r->billed_minor, 'currency' => $r->currency, 'reference' => $reference]);
            $this->audit->record('reinsurance.recovery.receipt', 'reinsurance_recovery', $id, ['reinsurer_id' => $reinsurerId, 'amount_minor' => $amount, 'reference' => $reference]);
            if ($settled >= (int) $r->billed_minor) {
                $this->transition($tenantId, $id, 'SETTLED', $actorId, fn () => ['settled_at' => now()], null, []);
            }

            return $this->show($tenantId, $id);
        });
    }

    public function dispute(string $tenantId, string $id, string $reason, ?string $actorId): array
    {
        return $this->transition($tenantId, $id, 'DISPUTED', $actorId, fn ($r) => ['dispute_reason' => $reason, 'status_before_dispute' => $r->status],
            'reinsurance.recovery.disputed', ['reason' => $reason]);
    }

    /** Close: after settlement, or withdraw an unbilled / abandon a disputed recovery (open obligations are cancelled). */
    public function close(string $tenantId, string $id, string $reason, ?string $actorId): array
    {
        return $this->transition($tenantId, $id, 'CLOSED', $actorId, function ($r) use ($reason, $actorId) {
            foreach (DB::table('reinsurance_recovery_shares')->where('recovery_id', $r->id)->get() as $s) {
                foreach ([$s->financial_obligation_id, $s->reinstatement_obligation_id] as $oid) {
                    if ($oid && in_array(DB::table('financial_obligations')->where('id', $oid)->value('status'), ObligationService::OPEN_STATUSES, true)) {
                        $this->obligations->cancel($oid, 'Reinsurance recovery closed: '.$reason, $actorId);
                    }
                }
            }

            return ['closed_at' => now(), 'close_reason' => $reason];
        }, 'reinsurance.recovery.closed', ['reason' => $reason]);
    }

    /** Per-treaty large-loss threshold (null clears it: no notification). */
    public function setLargeLossThreshold(string $tenantId, string $treatyId, ?int $threshold, string $reason): array
    {
        $t = $this->treaties->treaty($tenantId, $treatyId);
        if ($threshold !== null && $threshold <= 0) {
            $this->fail('threshold_minor', 'Threshold must be positive or null.');
        }
        DB::table('reinsurance_treaties')->where('id', $t->id)->update(['large_loss_threshold_minor' => $threshold, 'updated_at' => now()]);
        $this->audit->recordChange('reinsurance.treaty.large_loss_threshold_changed', 'reinsurance_treaty', $t->id,
            ['large_loss_threshold_minor' => $t->large_loss_threshold_minor], ['large_loss_threshold_minor' => $threshold], $reason);

        return (array) DB::table('reinsurance_treaties')->where('id', $t->id)->first();
    }

    /** REI screen: recoveries register with filters. */
    public function index(string $tenantId, array $filters = []): array
    {
        return DB::table('reinsurance_recoveries as r')->join('claims as c', 'c.id', '=', 'r.claim_id')->where('r.tenant_id', $tenantId)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('r.status', $s))
            ->when($filters['treaty_id'] ?? null, fn ($q, $t) => $q->where('r.treaty_id', $t))
            ->when($filters['claim_id'] ?? null, fn ($q, $c) => $q->where('r.claim_id', $c))
            ->when($filters['large_loss'] ?? null, fn ($q) => $q->whereNotNull('r.large_loss_notified_at'))
            ->orderByDesc('r.updated_at')->limit(500)
            ->get(['r.id', 'r.claim_id', 'c.claim_number', 'r.policy_id', 'r.source_type', 'r.treaty_id', 'r.treaty_type', 'r.currency', 'r.gross_incurred_minor',
                'r.recoverable_minor', 'r.agreed_minor', 'r.billed_minor', 'r.settled_minor', 'r.reinstatement_premium_minor', 'r.status', 'r.large_loss_notified_at', 'r.updated_at'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** REI screen: totals per status and per reinsurer (outstanding receivable). */
    public function summary(string $tenantId): array
    {
        $byStatus = DB::table('reinsurance_recoveries')->where('tenant_id', $tenantId)->groupBy('status', 'currency')
            ->get([DB::raw('status'), DB::raw('currency'), DB::raw('count(*) as count'), DB::raw('sum(recoverable_minor) as recoverable_minor'),
                DB::raw('sum(billed_minor) as billed_minor'), DB::raw('sum(settled_minor) as settled_minor')])->map(fn ($r) => (array) $r)->all();
        $byReinsurer = DB::table('reinsurance_recovery_shares as s')->join('reinsurance_recoveries as r', 'r.id', '=', 's.recovery_id')->join('reinsurers as re', 're.id', '=', 's.reinsurer_id')
            ->where('r.tenant_id', $tenantId)->where('r.status', '!=', 'CLOSED')->groupBy('s.reinsurer_id', 're.code', 'r.currency')
            ->get(['s.reinsurer_id', 're.code', 'r.currency', DB::raw('sum(s.recoverable_minor) as recoverable_minor'), DB::raw('sum(s.billed_minor) as billed_minor'),
                DB::raw('sum(s.settled_minor) as settled_minor'), DB::raw('sum(s.billed_minor - s.settled_minor) as outstanding_minor')])->map(fn ($r) => (array) $r)->all();

        return ['by_status' => $byStatus, 'by_reinsurer' => $byReinsurer];
    }

    private function transition(string $tenantId, string $id, string $to, ?string $actorId, callable $changes, ?string $event, array $payload): array
    {
        return DB::transaction(function () use ($tenantId, $id, $to, $actorId, $changes, $event, $payload) {
            $r = $this->find($tenantId, $id, lock: true);
            if (! in_array($to, self::TRANSITIONS[$r->status] ?? [], true)) {
                $this->fail('status', "Recovery cannot move from {$r->status} to {$to}.");
            }
            if ($to === 'AGREED' && (int) $r->recoverable_minor <= 0) {
                $this->fail('amount_minor', 'Nothing is recoverable on this source.');
            }
            $set = $changes($r);
            DB::table('reinsurance_recoveries')->where('id', $id)->update($set + ['status' => $to, 'version' => (int) $r->version + 1, 'updated_at' => now()]);
            $amount = (int) ($payload['amount_minor'] ?? ($set['agreed_minor'] ?? 0));
            $this->history($id, $to, $r->status, $to, $amount, null, null, $actorId, array_filter($payload, fn ($v) => $v !== null));
            $this->audit->record('reinsurance.recovery.'.strtolower($to), 'reinsurance_recovery', $id, ['from' => $r->status, 'to' => $to] + array_filter($payload, fn ($v) => $v !== null));
            if ($event !== null) {
                $this->outbox->record($event, 'reinsurance_recovery', $id, ['tenant_id' => $tenantId, 'claim_id' => $r->claim_id, 'from' => $r->status, 'to' => $to,
                    'currency' => $r->currency] + array_filter($payload, fn ($v) => $v !== null));
            }

            return $this->show($tenantId, $id);
        });
    }

    private function largeLoss(string $tenantId, string $claimId, int $grossIncurred, ?string $actorId): void
    {
        $rows = DB::table('reinsurance_recoveries as r')->join('reinsurance_treaties as t', 't.id', '=', 'r.treaty_id')
            ->where('r.claim_id', $claimId)->whereNull('r.large_loss_notified_at')->whereNotNull('t.large_loss_threshold_minor')
            ->whereRaw('t.large_loss_threshold_minor <= ?', [$grossIncurred])->get(['r.id', 'r.status', 't.large_loss_threshold_minor', 't.id as treaty_id']);
        foreach ($rows as $row) {
            DB::table('reinsurance_recoveries')->where('id', $row->id)->update(['large_loss_notified_at' => now(), 'updated_at' => now()]);
            $this->history($row->id, 'LARGE_LOSS', $row->status, $row->status, $grossIncurred, null, null, $actorId, ['threshold_minor' => (int) $row->large_loss_threshold_minor]);
            $this->outbox->record('reinsurance.recovery.large_loss_notified', 'reinsurance_recovery', $row->id, ['tenant_id' => $tenantId, 'claim_id' => $claimId,
                'treaty_id' => $row->treaty_id, 'gross_incurred_minor' => $grossIncurred, 'threshold_minor' => (int) $row->large_loss_threshold_minor]);
            if ($row->status === 'ESTIMATED') {
                $this->notify($tenantId, $row->id, $actorId, 'Large-loss threshold reached');
            }
        }
    }

    private function claimBasis(string $tenantId, string $claimId, bool $lock = false): array
    {
        $q = DB::table('claims')->where('tenant_id', $tenantId)->where('id', $claimId);
        $claim = ($lock ? $q->sharedLock() : $q)->first() ?? abort(404, 'Claim not found.');
        $policy = DB::table('policies')->where('tenant_id', $tenantId)->where('id', $claim->policy_id)->first() ?? abort(404, 'Policy not found.');
        $paid = (int) DB::table('claim_payments')->where('claim_id', $claimId)->where('status', 'PAID')->sum('amount_minor');
        if (Schema::hasTable('claim_settlements')) {
            $paid += (int) DB::table('claim_settlements')->where('claim_id', $claimId)->where('status', 'PAID')->whereNull('claim_payment_id')->sum('amount_minor');
        }
        $reserve = max((int) $claim->current_reserve_minor, 0);

        return [$claim, $policy, ['gross_paid_minor' => $paid, 'outstanding_reserve_minor' => $reserve, 'gross_incurred_minor' => $paid + $reserve]];
    }

    /** Recovery sources = current treaty cessions of the policy + facultative placements (if E6's table exists). */
    private function sources(string $tenantId, object $policy): array
    {
        $out = [];
        foreach ($this->cessions->forPolicy($tenantId, $policy->id)['cessions'] as $c) {
            $v = $this->treaties->version($tenantId, $c['treaty_version_id']);
            $out[] = ['source_type' => 'TREATY', 'source_key' => $c['treaty_version_id'], 'treaty_id' => $c['treaty_id'], 'treaty_version_id' => $c['treaty_version_id'],
                'cession_id' => $c['id'], 'treaty_type' => $c['treaty_type'], 'ceded_percent' => (float) $c['ceded_percent'], 'layers' => $c['layers'] ?? [],
                'treaty_layers' => $v['layers'] ?? [], 'attachment_ratio' => $v['attachment_ratio'] ?? null, 'limit_ratio' => $v['limit_ratio'] ?? null,
                'shares' => array_map(fn ($s) => ['reinsurer_id' => $s['reinsurer_id'], 'share_percent' => (float) $s['share_percent']], $c['shares'])];
        }

        return array_merge($this->facultative($policy->id), $out);
    }

    private function facultative(string $policyId): array
    {
        foreach (self::FACULTATIVE_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, ['policy_id', 'reinsurer_id'])) {
                continue;
            }
            $pctCol = collect(['ceded_percent', 'cession_percent', 'share_percent'])->first(fn ($c) => Schema::hasColumn($table, $c));
            if (! $pctCol) {
                continue;
            }
            $rows = DB::table($table)->where('policy_id', $policyId)
                ->when(Schema::hasColumn($table, 'status'), fn ($q) => $q->whereNotIn('status', ['DRAFT', 'CANCELLED', 'DECLINED', 'SUPERSEDED', 'REJECTED', 'EXPIRED']))->get();

            return $rows->map(fn ($f) => ['source_type' => 'FACULTATIVE', 'source_key' => (string) $f->id, 'treaty_type' => 'FACULTATIVE', 'ceded_percent' => (float) $f->{$pctCol},
                'shares' => [['reinsurer_id' => $f->reinsurer_id, 'share_percent' => 100.0]]])->all();
        }

        return [];
    }

    /** Stop loss: aggregate retained losses of all claims under the treaty version vs its subject premium. */
    private function stopLoss(string $claimId): callable
    {
        return function (array $s, int $net) use ($claimId): int {
            if ($s['attachment_ratio'] === null || $s['limit_ratio'] === null) {
                return 0;
            }
            $others = DB::table('reinsurance_recoveries')->where('treaty_version_id', $s['treaty_version_id'])->where('claim_id', '!=', $claimId);
            $otherLoss = (int) (clone $others)->sum('subject_loss_minor');
            $otherRecovered = (int) (clone $others)->sum(DB::raw('COALESCE(agreed_minor, recoverable_minor)'));
            $premium = 0;
            foreach (DB::table('reinsurance_cessions')->where('treaty_version_id', $s['treaty_version_id'])->where('status', 'CALCULATED')->get(['policy_id', 'run', 'gross_premium_minor']) as $c) {
                $proportional = (int) DB::table('reinsurance_cessions')->where('policy_id', $c->policy_id)->where('run', $c->run)
                    ->whereIn('treaty_type', ['QUOTA_SHARE', 'SURPLUS'])->sum('ceded_premium_minor');
                $premium += (int) $c->gross_premium_minor - $proportional;
            }
            $total = RecoveryCalculator::stopLossAggregate($otherLoss + $net, $premium, (float) $s['attachment_ratio'], (float) $s['limit_ratio']);

            return max($total - $otherRecovered, 0);
        };
    }

    private function writeShares(string $recoveryId, array $r): void
    {
        foreach ($r['shares'] as $i => $s) {
            DB::table('reinsurance_recovery_shares')->updateOrInsert(['recovery_id' => $recoveryId, 'reinsurer_id' => $s['reinsurer_id']],
                ['share_percent' => $s['share_percent'], 'recoverable_minor' => $r['share_amounts'][$i] ?? 0, 'reinstatement_premium_minor' => $r['share_reinstatement'][$i] ?? 0,
                    'updated_at' => now()] + (DB::table('reinsurance_recovery_shares')->where(['recovery_id' => $recoveryId, 'reinsurer_id' => $s['reinsurer_id']])->exists()
                    ? [] : ['id' => (string) Str::uuid(), 'created_at' => now()]));
        }
    }

    private function changed(object $row, array $values): bool
    {
        foreach (['gross_incurred_minor', 'gross_paid_minor', 'subject_loss_minor', 'recoverable_minor', 'recoverable_paid_minor', 'reinstatement_premium_minor'] as $k) {
            if ((int) $row->{$k} !== (int) $values[$k]) {
                return true;
            }
        }

        return false;
    }

    private function history(string $id, string $type, ?string $from, string $to, int $amount, ?string $reinsurerId, ?string $reference, ?string $actorId, array $meta = []): string
    {
        $eid = (string) Str::uuid();
        DB::table('reinsurance_recovery_events')->insert(['id' => $eid, 'recovery_id' => $id, 'event_type' => $type, 'from_status' => $from, 'to_status' => $to,
            'amount_minor' => $amount, 'reinsurer_id' => $reinsurerId, 'reference' => $reference, 'actor_id' => $actorId,
            'metadata' => $meta === [] ? null : json_encode($meta), 'occurred_at' => now()]);

        return $eid;
    }

    private function find(string $tenantId, string $id, bool $lock = false): object
    {
        $q = DB::table('reinsurance_recoveries')->where('tenant_id', $tenantId)->where('id', $id);

        return ($lock ? $q->lockForUpdate() : $q)->first() ?? abort(404, 'Recovery not found.');
    }

    private function strip(array $r): array
    {
        return array_intersect_key($r, array_flip(['source_type', 'source_key', 'treaty_id', 'treaty_version_id', 'treaty_type', 'ceded_percent', 'subject_loss_minor',
            'recoverable_minor', 'recovery_layers', 'reinstatement_premium_minor'])) + ['shares' => array_map(fn ($s, $a) => $s + ['recoverable_minor' => $a], $r['shares'], $r['share_amounts'])];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
