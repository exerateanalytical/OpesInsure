<?php

declare(strict_types=1);

namespace App\Application\Claims\Decisions;

use App\Application\Audit\AuditWriter;
use App\Application\Authority\AuthorityService;
use App\Application\Claims\ClaimLifecycleService;
use App\Application\Claims\ClaimReferenceCodes;
use App\Application\Events\OutboxWriter;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\ClaimDecision;
use App\Models\ClaimDispute;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CLM-012 claim decision (WF-056..058, WF-062).
 *
 *  - propose: APPROVE | PARTIAL | DECLINE with catalogued reason codes, a rationale and the amount per head
 *    (heads sum to the decided amount). The maker's CLAIM_SETTLE authority is checked through AuthorityService:
 *    over limit (or no limit) → REFERRED with an AUTHORITY_REFERRAL case; within limit → PENDING_APPROVAL.
 *  - approve (checker ≠ maker): a PENDING_APPROVAL decision needs a checker whose CLAIM_SETTLE limit covers the
 *    amount; a REFERRED one needs a supervisor (claims.decision.supervise) whose limit covers it. The claim is
 *    moved by ClaimLifecycleService only. The customer is notified with the reasons.
 *  - return: the checker sends the proposal back (status RETURNED, reason kept); the claim does not move.
 *  - appeal: opens a dispute (ClaimLifecycleService::openDispute); the appeal decision is a NEW row
 *    (kind APPEAL, appeal_of_decision_id = the decided original). Decided rows are immutable (DB trigger).
 */
final class ClaimDecisionService
{
    public const HEADS = ClaimReferenceCodes::RESERVE_TYPES;

    private const TARGET = ['APPROVE' => 'APPROVED', 'PARTIAL' => 'PARTIALLY_APPROVED', 'DECLINE' => 'DECLINED'];

    private const OPEN = ['PENDING_APPROVAL', 'REFERRED'];

    public function __construct(
        private readonly ClaimLifecycleService $lifecycle,
        private readonly AuthorityService $authority,
        private readonly DecisionNotifier $notifier,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @param array{decision: string, reason_codes: list<string>, rationale: string, heads?: list<array{head: string, amount_minor: int}>} $d */
    public function propose(Claim $claim, array $d, User $actor): ClaimDecision
    {
        $decision = ClaimReferenceCodes::decisionForApi((string) $d['decision']) ?? throw ValidationException::withMessages(['decision' => 'Unknown decision code.']);
        $reasons = $this->reasons($decision, $d['reason_codes'] ?? []);
        [$heads, $amount] = $this->heads($decision, $d['heads'] ?? []);

        return DB::transaction(function () use ($claim, $d, $actor, $decision, $reasons, $heads, $amount) {
            $c = Claim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            $this->tenant($c);
            if (ClaimDecision::where('claim_id', $c->id)->whereIn('status', self::OPEN)->exists()) {
                throw ValidationException::withMessages(['status' => __('wave7.decision_pending')]);
            }
            [$kind, $original, $dispute] = $this->stage($c);
            if ($decision === 'PARTIAL' && $c->estimated_loss_minor !== null && $amount >= (int) $c->estimated_loss_minor) {
                throw ValidationException::withMessages(['heads' => 'A partial decision must be below the claimed amount.']);
            }

            $row = ClaimDecision::create([
                'claim_id' => $c->id, 'decision' => $decision, 'approved_amount_minor' => $amount, 'currency' => $c->currency,
                'reason_code' => $reasons[0], 'reason_codes' => $reasons, 'rationale' => $d['rationale'], 'heads' => $heads,
                'authority_snapshot' => [], 'status' => 'PENDING_APPROVAL', 'proposed_by' => $actor->id,
                'kind' => $kind, 'appeal_of_decision_id' => $original?->id, 'dispute_id' => $dispute?->id,
            ]);
            $check = $this->authority->checkStaffLimit($c->tenant_id, $c->policy->carrier_id, $actor, 'CLAIM_SETTLE', $amount, $c->currency,
                ['type' => 'claim_decision', 'id' => $row->id, 'title' => 'Claim '.$c->claim_number.' decision'], 'CLAIM_DECISION');
            $row->update([
                'status' => $check->referred() ? 'REFERRED' : 'PENDING_APPROVAL', 'authority_snapshot' => ['maker' => $check->snapshot()],
                'authority_check_id' => $check->checkId, 'referral_case_id' => $check->referralCaseId,
            ]);
            $payload = ['decision_id' => $row->id, 'decision' => $decision, 'kind' => $kind, 'amount_minor' => $amount, 'reason_codes' => $reasons];
            $this->audit->record('claim.decision.proposed', 'claim_decision', $row->id, $payload);
            $this->outbox->record('claim.decision.proposed', 'claim', $c->id, $payload);
            if ($check->referred()) {
                $this->outbox->record('claim.decision.referred', 'claim', $c->id, ['decision_id' => $row->id, 'referral_case_id' => $check->referralCaseId, 'reason' => $check->reason]);
            }

            return $row->refresh();
        });
    }

    public function approve(ClaimDecision $decision, User $actor): ClaimDecision
    {
        return DB::transaction(function () use ($decision, $actor) {
            $d = ClaimDecision::whereKey($decision->id)->lockForUpdate()->firstOrFail();
            $c = Claim::whereKey($d->claim_id)->firstOrFail();
            $this->tenant($c);
            if (! in_array($d->status, self::OPEN, true) || $d->proposed_by === $actor->id) {
                throw ValidationException::withMessages(['status' => __('wave7.approval_invalid')]);
            }
            if ($d->status === 'REFERRED' && ! $actor->hasPermission('claims.decision.supervise')) {
                throw ValidationException::withMessages(['authority' => 'A referred decision needs a supervisor approval.']);
            }
            $check = $this->authority->checkStaffLimit($c->tenant_id, $c->policy->carrier_id, $actor, 'CLAIM_SETTLE', (int) $d->approved_amount_minor, $d->currency,
                ['type' => 'claim_decision', 'id' => $d->id], 'CLAIM_DECISION_APPROVE', null, false);
            if (! $check->allowed()) {
                throw ValidationException::withMessages(['authority' => __('wave7.authority_exceeded')]);
            }

            if ($d->kind === 'APPEAL') {
                $dispute = ClaimDispute::findOrFail($d->dispute_id);
                $this->lifecycle->resolveDispute($dispute, 'Appeal decided: '.$d->reason_code, true, $actor);
            }
            $to = self::TARGET[$d->decision];
            $this->lifecycle->transition($c, $to, $d->reason_code, ['decision_id' => $d->id, 'kind' => $d->kind, 'appeal_of_decision_id' => $d->appeal_of_decision_id], $actor);
            Claim::whereKey($c->id)->update(['approved_amount_minor' => $d->approved_amount_minor]);

            $d->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now(),
                'authority_snapshot' => ($d->authority_snapshot ?? []) + ['checker' => $check->snapshot()]]);
            $payload = ['decision_id' => $d->id, 'decision' => $d->decision, 'kind' => $d->kind, 'amount_minor' => (int) $d->approved_amount_minor, 'referral_case_id' => $d->referral_case_id];
            $this->audit->record('claim.decision.approved', 'claim_decision', $d->id, $payload);
            $this->outbox->record($d->decision === 'DECLINE' ? 'claim.decision.rejected' : 'claim.decision.approved', 'claim', $c->id, $payload);

            $c = $c->refresh();
            app(\App\Application\Documents\Engine\DocumentEngine::class)->fireQuietly(['APPROVED' => 'CLAIM_APPROVED', 'PARTIALLY_APPROVED' => 'CLAIM_PARTIALLY_APPROVED', 'DECLINED' => 'CLAIM_DECLINED'][$c->status], $c->policy, ['claim' => $c], $actor);
            $this->notifier->notify($c, $d->refresh());

            return $d->refresh();
        });
    }

    /** The checker returns the proposal to the maker (no claim move). */
    public function returnToMaker(ClaimDecision $decision, string $reason, User $actor): ClaimDecision
    {
        return DB::transaction(function () use ($decision, $reason, $actor) {
            $d = ClaimDecision::whereKey($decision->id)->lockForUpdate()->firstOrFail();
            $this->tenant(Claim::findOrFail($d->claim_id));
            if (! in_array($d->status, self::OPEN, true) || $d->proposed_by === $actor->id) {
                throw ValidationException::withMessages(['status' => __('wave7.approval_invalid')]);
            }
            $d->update(['status' => 'RETURNED', 'returned_by' => $actor->id, 'returned_at' => now(), 'return_reason' => $reason]);
            $this->audit->record('claim.decision.returned', 'claim_decision', $d->id, ['claim_id' => $d->claim_id], $reason);
            $this->outbox->record('claim.decision.returned', 'claim', $d->claim_id, ['decision_id' => $d->id]);

            return $d->refresh();
        });
    }

    /** Customer/handler lodges an appeal against the decided original (claim → DISPUTED). */
    public function appeal(Claim $claim, string $reasonCode, string $statement, User $actor): ClaimDispute
    {
        return DB::transaction(function () use ($claim, $reasonCode, $statement, $actor) {
            $c = Claim::whereKey($claim->id)->firstOrFail();
            $this->tenant($c);
            $original = $this->lastDecided($c) ?? throw ValidationException::withMessages(['status' => __('wave7.dispute_invalid')]);
            $dispute = $this->lifecycle->openDispute($c, ['reason_code' => $reasonCode, 'statement' => $statement], $actor);
            $this->audit->record('claim.decision.appealed', 'claim_decision', $original->id, ['dispute_id' => $dispute->id], $reasonCode);
            $this->outbox->record('claim.decision.appealed', 'claim', $c->id, ['decision_id' => $original->id, 'dispute_id' => $dispute->id]);

            return $dispute;
        });
    }

    /** @return list<ClaimDecision> every decision of the claim, originals and appeals, oldest first */
    public function history(Claim $claim): array
    {
        $this->tenant($claim);

        return ClaimDecision::where('claim_id', $claim->id)->orderBy('created_at')->get()->all();
    }

    /** @return array{0: string, 1: ?ClaimDecision, 2: ?ClaimDispute} */
    private function stage(Claim $c): array
    {
        if ($c->status === 'CARRIER_REVIEW') {
            return ['ORIGINAL', null, null];
        }
        if ($c->status === 'DISPUTED') {
            $dispute = ClaimDispute::where(['claim_id' => $c->id, 'status' => 'OPEN'])->first();
            $original = $this->lastDecided($c);
            if ($dispute && $original) {
                return ['APPEAL', $original, $dispute];
            }
        }
        throw ValidationException::withMessages(['status' => __('wave7.decision_stage_invalid')]);
    }

    private function lastDecided(Claim $c): ?ClaimDecision
    {
        return ClaimDecision::where(['claim_id' => $c->id, 'status' => 'APPROVED'])->orderByDesc('approved_at')->first();
    }

    /** @return list<string> */
    private function reasons(string $decision, array $codes): array
    {
        $codes = array_values(array_unique(array_map('strtoupper', $codes)));
        if ($codes === []) {
            throw ValidationException::withMessages(['reason_codes' => 'At least one reason code is required.']);
        }
        $valid = DB::table('claim_decision_reason_codes')->where('status', 'ACTIVE')->whereIn('code', $codes)
            ->whereIn('applies_to', [$decision, 'ANY'])->pluck('code')->all();
        if ($missing = array_diff($codes, $valid)) {
            throw ValidationException::withMessages(['reason_codes' => 'Reason codes not allowed for '.$decision.': '.implode(', ', $missing)]);
        }

        return $codes;
    }

    /** @return array{0: list<array{head: string, amount_minor: int}>, 1: int} */
    private function heads(string $decision, array $heads): array
    {
        $out = [];
        foreach ($heads as $h) {
            $head = strtoupper((string) ($h['head'] ?? ''));
            $amount = (int) ($h['amount_minor'] ?? -1);
            if (! in_array($head, self::HEADS, true) || $amount < 0 || isset($out[$head])) {
                throw ValidationException::withMessages(['heads' => 'Invalid or duplicate head '.$head.'.']);
            }
            $out[$head] = ['head' => $head, 'amount_minor' => $amount];
        }
        $total = array_sum(array_column($out, 'amount_minor'));
        if ($decision === 'DECLINE' && $total !== 0) {
            throw ValidationException::withMessages(['heads' => 'A declined claim carries no amount.']);
        }
        if ($decision !== 'DECLINE' && $total <= 0) {
            throw ValidationException::withMessages(['heads' => 'An approval needs an amount per head.']);
        }

        return [array_values($out), $total];
    }

    private function tenant(Claim $c): void
    {
        if ($c->tenant_id !== app(TenantContext::class)->id()) {
            abort(404);
        }
    }
}
