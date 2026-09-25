<?php

declare(strict_types=1);

namespace App\Application\Claims\Reserves;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Ledger\FinancialPostingService;
use App\Application\Ledger\LedgerService;
use App\Application\Ledger\Posting\AccountingEventMappingService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\ClaimReserveChange;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CLM-008 — event-based claim reserves on claim_reserve_changes (the Wave 7 maker-checker table).
 *
 *  - A reserve is kept per head (INDEMNITY | EXPENSE) and optional coverage_code. Every request is a movement row
 *    with a reason code; one pending movement per head/coverage at a time.
 *  - Stage: the first approved movement of a head/coverage is INITIAL, later ones CURRENT; the maker may mark a
 *    movement FINAL, after which that head/coverage accepts no further movement.
 *  - Approval (checker ≠ maker, DB-enforced) runs the checker's RESERVE_APPROVE authority limit
 *    (ReserveAuthority). Over the limit the movement becomes REFERRED with an AUTHORITY_REFERRAL case — not an
 *    error; an approver with enough authority approves it later.
 *  - On approval: claims.current_reserve_minor = sum of the latest approved amount per head/coverage, the signed
 *    delta is posted on accounting event claim.reserve.changed (a decrease posts the mapped accounts reversed),
 *    and the limit ledger (C4, optional) is told about the movement.
 */
final class ClaimReserveService
{
    public const HEADS = ['INDEMNITY', 'EXPENSE'];

    public const STAGES = ['INITIAL', 'CURRENT', 'FINAL'];

    public const REASON_CODES = [
        'INITIAL_ESTIMATE', 'NEW_INFORMATION', 'ASSESSMENT', 'EXPERT_REPORT', 'LEGAL_DEVELOPMENT', 'MEDICAL_UPDATE',
        'PAYMENT_MADE', 'RECOVERY_EXPECTED', 'CORRECTION', 'FINAL_SETTLEMENT', 'CLOSURE', 'REOPENED', 'OTHER',
    ];

    /** Sub-types (ClaimReferenceCodes::RESERVE_TYPES) that are expense heads; everything else is indemnity. */
    private const EXPENSE_TYPES = ['LEGAL', 'ADJUSTER'];

    public const EVENT = 'claim.reserve.changed';

    public function __construct(
        private readonly ReserveAuthority $authority,
        private readonly FinancialPostingService $posting,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @param array{amount_minor:int, reason_code:string, notes?:?string, reserve_type?:?string, reserve_head?:?string, coverage_code?:?string, final?:bool} $d */
    public function request(Claim $claim, array $d, User $actor): ClaimReserveChange
    {
        $amount = (int) $d['amount_minor'];
        if ($amount < 0) {
            throw ValidationException::withMessages(['amount_minor' => __('wave7.amount_invalid')]);
        }
        $type = $d['reserve_type'] ?? null;
        $head = $d['reserve_head'] ?? (in_array($type, self::EXPENSE_TYPES, true) ? 'EXPENSE' : 'INDEMNITY');
        $coverage = $d['coverage_code'] ?? null;

        return DB::transaction(function () use ($claim, $d, $actor, $amount, $type, $head, $coverage) {
            $c = Claim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            $this->tenant($c);
            if ($coverage !== null) {
                $this->assertCoverage($c, $coverage);
            }
            $slot = $this->slot($c->id, $head, $coverage);
            if ((clone $slot)->whereIn('status', ['PENDING_APPROVAL', 'REFERRED'])->exists()) {
                throw ValidationException::withMessages(['status' => __('wave7.reserve_pending')]);
            }
            $latest = (clone $slot)->where('status', 'APPROVED')->orderByDesc('approval_seq')->first();
            if ($latest?->reserve_stage === 'FINAL') {
                throw ValidationException::withMessages(['reserve_stage' => 'The '.$head.' reserve is final; reopen the claim before moving it.']);
            }
            $stage = ! empty($d['final']) ? 'FINAL' : ($latest ? 'CURRENT' : 'INITIAL');

            $r = ClaimReserveChange::create([
                'claim_id' => $c->id, 'previous_amount_minor' => (int) ($latest?->requested_amount_minor ?? 0), 'requested_amount_minor' => $amount,
                'currency' => $c->currency, 'status' => 'PENDING_APPROVAL', 'reason_code' => $d['reason_code'], 'reserve_type' => $type,
                'reserve_head' => $head, 'coverage_code' => $coverage, 'reserve_stage' => $stage, 'notes' => $d['notes'] ?? null, 'requested_by' => $actor->id,
            ]);
            $this->audit->record('claim.reserve.requested', 'claim_reserve_change', $r->id, ['head' => $head, 'coverage_code' => $coverage, 'stage' => $stage, 'amount_minor' => $amount], $d['reason_code']);
            $this->outbox->record('claim.reserve.requested', 'claim', $c->id, ['reserve_change_id' => $r->id, 'reserve_head' => $head, 'coverage_code' => $coverage, 'reserve_stage' => $stage, 'amount_minor' => $amount]);

            return $r->refresh();
        });
    }

    /** Checker approval. Returns the row APPROVED, or REFERRED when the checker's RESERVE_APPROVE limit is exceeded. */
    public function approve(ClaimReserveChange $change, User $actor): ClaimReserveChange
    {
        return DB::transaction(function () use ($change, $actor) {
            $r = ClaimReserveChange::whereKey($change->id)->lockForUpdate()->firstOrFail();
            $c = Claim::whereKey($r->claim_id)->lockForUpdate()->firstOrFail();
            $this->tenant($c);
            if (! in_array($r->status, ['PENDING_APPROVAL', 'REFERRED'], true) || $r->requested_by === $actor->id) {
                throw ValidationException::withMessages(['status' => __('wave7.approval_invalid')]);
            }

            $outcome = $this->authority->check($c, $r, $actor);
            if ($outcome->referred()) {
                $r->update(['status' => 'REFERRED', 'authority_check_id' => $outcome->checkId, 'referral_case_id' => $outcome->referralCaseId]);
                $this->audit->record('claim.reserve.referred', 'claim_reserve_change', $r->id, $outcome->snapshot());
                $this->outbox->record('claim.reserve.referred', 'claim', $c->id, ['reserve_change_id' => $r->id, 'referral_case_id' => $outcome->referralCaseId, 'reason' => $outcome->reason]);

                return $r->refresh();
            }

            $previous = $this->latestApprovedAmount($c->id, $r->reserve_head, $r->coverage_code);
            $movement = (int) $r->requested_amount_minor - $previous;
            $r->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now(), 'previous_amount_minor' => $previous,
                'movement_minor' => $movement, 'approval_seq' => (int) ClaimReserveChange::where('claim_id', $c->id)->max('approval_seq') + 1, 'authority_check_id' => $outcome->checkId]);
            $total = $this->total($c->id);
            $c->update(['current_reserve_minor' => $total, 'version' => $c->version + 1]);

            $journal = $movement === 0 ? null : $this->post($c, $r, $movement);
            $limitRef = $this->reserveLimit($c, $r, $movement);
            $r->update(['journal_id' => $journal, 'limit_reservation_ref' => $limitRef]);

            $payload = ['reserve_change_id' => $r->id, 'reserve_head' => $r->reserve_head, 'coverage_code' => $r->coverage_code, 'reserve_stage' => $r->reserve_stage,
                'amount_minor' => (int) $r->requested_amount_minor, 'movement_minor' => $movement, 'total_reserve_minor' => $total, 'currency' => $r->currency, 'journal_id' => $journal];
            $this->audit->record('claim.reserve.approved', 'claim_reserve_change', $r->id, $payload);
            $this->outbox->record('claim.reserve.approved', 'claim', $c->id, ['reserve_change_id' => $r->id]);
            $this->outbox->record('claim.reserve.changed', 'claim', $c->id, $payload);

            return $r->refresh();
        });
    }

    /** Current reserve position per head/coverage (latest approved row of each). */
    public function position(Claim $claim): Collection
    {
        return ClaimReserveChange::where('claim_id', $claim->id)->where('status', 'APPROVED')->orderBy('approval_seq')->get()
            ->groupBy(fn ($r) => $r->reserve_head.'|'.($r->coverage_code ?? ''))
            ->map(fn ($rows) => ['reserve_head' => $rows->last()->reserve_head, 'coverage_code' => $rows->last()->coverage_code,
                'reserve_stage' => $rows->last()->reserve_stage, 'amount_minor' => (int) $rows->last()->requested_amount_minor,
                'initial_minor' => (int) $rows->first()->requested_amount_minor, 'currency' => $rows->last()->currency, 'movements' => $rows->count()])
            ->values();
    }

    private function post(Claim $c, ClaimReserveChange $r, int $movement): string
    {
        $correlation = 'claim-reserve:'.$r->id;
        if ($movement > 0) {
            return $this->posting->post($c->tenant_id, self::EVENT, $r->id, $movement, $r->currency, $correlation);
        }
        // A release reverses the mapped entry; FinancialPostingService only accepts positive increases.
        $existing = DB::table('journals')->where(['reference_type' => self::EVENT, 'reference_id' => $r->id, 'status' => 'POSTED'])->value('id');
        if ($existing) {
            return $existing;
        }
        $m = app(AccountingEventMappingService::class)->resolve($c->tenant_id, self::EVENT, $r->currency);
        if (! $m) {
            throw ValidationException::withMessages(['posting_profile' => __('wave4.posting_profile_missing')]);
        }
        class_exists(\App\Domain\Ledger\Journal::class);
        $dims = ['event' => self::EVENT, 'source' => 'event_mapping', 'mapping_id' => $m['mapping_id'], 'mapping_version' => $m['version'], 'direction' => 'RELEASE'];
        $id = app(LedgerService::class)->post($c->tenant_id, self::EVENT, $r->id, $r->currency, [
            ['account_id' => $m['credit_account_id'], 'debit_minor' => -$movement, 'credit_minor' => 0, 'dimensions' => $dims],
            ['account_id' => $m['debit_account_id'], 'debit_minor' => 0, 'credit_minor' => -$movement, 'dimensions' => $dims],
        ], $correlation);
        $this->audit->record('ledger.event.posted', 'journal', $id, ['event' => self::EVENT, 'reference_id' => $r->id] + $dims);
        $this->outbox->record('ledger.event.posted', 'journal', $id, ['journal_id' => $id, 'event' => self::EVENT]);

        return $id;
    }

    /** Batch 11 C4 limit ledger, when present: reserve (or release) the movement against the policy limit. */
    private function reserveLimit(Claim $c, ClaimReserveChange $r, int $movement): ?string
    {
        $class = 'App\\Application\\Claims\\Limits\\LimitLedger';
        if ($movement === 0 || ! class_exists($class) || ! method_exists($class, 'reserve')) {
            return null;
        }
        $ref = app($class)->reserve($c, $r->coverage_code, $movement, 'claim_reserve_change', $r->id);

        return is_scalar($ref) ? mb_substr((string) $ref, 0, 64) : (is_object($ref) && isset($ref->id) ? (string) $ref->id : null);
    }

    private function assertCoverage(Claim $c, string $coverage): void
    {
        $known = DB::table('policy_coverages')->where('policy_id', $c->policy_id)->distinct()->pluck('coverage_code');
        if ($known->isNotEmpty() && ! $known->contains($coverage)) {
            throw ValidationException::withMessages(['coverage_code' => 'Coverage '.$coverage.' is not on the policy.']);
        }
    }

    private function slot(string $claimId, string $head, ?string $coverage)
    {
        return ClaimReserveChange::where('claim_id', $claimId)->where('reserve_head', $head)
            ->when($coverage === null, fn ($q) => $q->whereNull('coverage_code'), fn ($q) => $q->where('coverage_code', $coverage));
    }

    private function latestApprovedAmount(string $claimId, string $head, ?string $coverage): int
    {
        return (int) ($this->slot($claimId, $head, $coverage)->where('status', 'APPROVED')->orderByDesc('approval_seq')->value('requested_amount_minor') ?? 0);
    }

    private function total(string $claimId): int
    {
        return (int) ClaimReserveChange::where('claim_id', $claimId)->where('status', 'APPROVED')->orderBy('approval_seq')->get()
            ->groupBy(fn ($r) => $r->reserve_head.'|'.($r->coverage_code ?? ''))->sum(fn ($rows) => (int) $rows->last()->requested_amount_minor);
    }

    private function tenant(Claim $c): void
    {
        if ($c->tenant_id !== app(TenantContext::class)->id()) {
            abort(404);
        }
    }
}
