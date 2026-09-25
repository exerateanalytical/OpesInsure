<?php

declare(strict_types=1);

namespace App\Application\Claims\Closure;

use App\Models\Claim;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CLM-014 / WF-060 closure checklist. Each item is evaluated against live data; a claim may close
 * only when every item passes. Item codes double as the ClaimTransitionGuard blocking reason codes.
 */
final class ClaimClosureChecklist
{
    /** Closure reasons (WF-060). Reasons in NO_DECISION_REASONS close without a recorded decision. */
    public const REASONS = ['SETTLED_PAID', 'DECLINED_FINAL', 'NO_PAYMENT_DUE', 'WITHDRAWN', 'DUPLICATE', 'OPENED_IN_ERROR', 'AUTO_INACTIVE_SETTLED'];

    public const NO_DECISION_REASONS = ['WITHDRAWN', 'DUPLICATE', 'OPENED_IN_ERROR'];

    /** Claim payment statuses that are no longer pending. */
    private const PAYMENT_DONE = ['PAID', 'REVERSED', 'REJECTED', 'CANCELLED'];

    /** Recovery statuses that count as resolved or transferred. */
    public const RECOVERY_DONE = ['CLOSED', 'TRANSFERRED', 'ABANDONED', 'WRITTEN_OFF'];

    public const ITEMS = ['RESERVES_ZERO', 'NO_PENDING_PAYMENTS', 'RECOVERIES_RESOLVED', 'EVIDENCE_REVIEWED', 'DECISION_RECORDED', 'OPEN_WORK_CLOSED'];

    /** @return list<array{code:string,passed:bool,detail:array}> */
    public function evaluate(Claim $c, ?string $reason = null): array
    {
        $pendingReserve = DB::table('claim_reserve_changes')->where(['claim_id' => $c->id, 'status' => 'PENDING_APPROVAL'])->count();
        $pendingPayments = DB::table('claim_payments')->where('claim_id', $c->id)->whereNotIn('status', self::PAYMENT_DONE)->count();
        $obligations = DB::table('financial_obligations')->where('kind', 'PAYABLE')->whereIn('status', ['OPEN', 'PARTIALLY_SETTLED'])
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('source_type', 'claim')->where('source_id', $c->id))
                ->orWhere(fn ($w) => $w->where('source_type', 'claim_payment')->whereIn('source_id', DB::table('claim_payments')->select('id')->where('claim_id', $c->id))))
            ->count();
        $recoveries = DB::table('claim_recoveries')->where('claim_id', $c->id)->whereNotIn('status', self::RECOVERY_DONE)->count();
        $evidence = DB::table('claim_documents')->where('claim_id', $c->id)->where('status', 'SUBMITTED')->count();
        $decision = DB::table('claim_decisions')->where(['claim_id' => $c->id, 'status' => 'APPROVED'])->exists();
        $cases = DB::table('cases')->whereNull('closed_at')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('subject_type', 'claim')->where('subject_id', $c->id))->orWhere(fn ($w) => $w->where('source_type', 'claim')->where('source_id', $c->id)));
        $openCases = (clone $cases)->count();
        $openTasks = DB::table('case_tasks')->whereIn('case_id', DB::table('cases')->select('id')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('subject_type', 'claim')->where('subject_id', $c->id))->orWhere(fn ($w) => $w->where('source_type', 'claim')->where('source_id', $c->id))))
            ->whereNull('completed_at')->whereNotIn('status', ['DONE', 'COMPLETED', 'CANCELLED', 'SKIPPED'])->count();

        return [
            ['code' => 'RESERVES_ZERO', 'passed' => (int) $c->current_reserve_minor === 0 && $pendingReserve === 0, 'detail' => ['current_reserve_minor' => (int) $c->current_reserve_minor, 'pending_reserve_changes' => $pendingReserve]],
            ['code' => 'NO_PENDING_PAYMENTS', 'passed' => $pendingPayments === 0 && $obligations === 0, 'detail' => ['pending_payments' => $pendingPayments, 'open_obligations' => $obligations]],
            ['code' => 'RECOVERIES_RESOLVED', 'passed' => $recoveries === 0, 'detail' => ['open_recoveries' => $recoveries]],
            ['code' => 'EVIDENCE_REVIEWED', 'passed' => $evidence === 0, 'detail' => ['unreviewed_evidence' => $evidence]],
            ['code' => 'DECISION_RECORDED', 'passed' => $decision || in_array($reason, self::NO_DECISION_REASONS, true), 'detail' => ['approved_decision' => $decision]],
            ['code' => 'OPEN_WORK_CLOSED', 'passed' => $openCases === 0 && $openTasks === 0, 'detail' => ['open_cases' => $openCases, 'open_tasks' => $openTasks]],
        ];
    }

    /** First failing item code, or null when the claim may close. */
    public function firstFailure(Claim $c, ?string $reason = null): ?string
    {
        foreach ($this->evaluate($c, $reason) as $item) {
            if (! $item['passed']) {
                return 'CLOSURE_'.$item['code'];
            }
        }

        return null;
    }
}
