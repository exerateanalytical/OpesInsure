<?php

declare(strict_types=1);

namespace App\Application\Underwriting;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\Proposal;
use App\Models\UnderwritingCase;
use App\Models\UnderwritingDecision;
use App\Models\UnderwritingReferralTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-UW-001 / REQ-UW-003 — human underwriting workflow on underwriting_cases (canonical states: UnderwritingCaseMachine).
 *
 *  assign             REFERRED → ASSIGNED (reassignment keeps the current state)
 *  startReview        ASSIGNED → REVIEWING
 *  readyForDecision   REVIEWING → DECISION_PENDING (no open referral task)
 *  requestInformation REFERRED/ASSIGNED/REVIEWING/DECISION_PENDING → INFORMATION_REQUESTED with exact items, sent to the
 *                     proposal originator through ProposalService (proposal INFORMATION_REQUIRED); the proposer's
 *                     resubmission returns the case to the requesting underwriter (ProposalService::resubmit).
 *  decide             → APPROVED | CONDITIONAL | COUNTEROFFERED | DECLINED. Always human; records the latest system
 *                     evaluation and its recommendation. The proposal moves only through the proposal machine.
 */
final class UnderwritingService
{
    /** API decision code => [proposal machine decision, case outcome] */
    public const DECISIONS = [
        'APPROVED' => ['APPROVED', 'APPROVED'],
        'CONDITIONAL' => ['APPROVED', 'CONDITIONAL'],
        'COUNTEROFFERED' => ['COUNTEROFFERED', 'COUNTEROFFERED'],
        'DECLINED' => ['DECLINED', 'DECLINED'],
    ];

    public const ITEM_KINDS = ['DOCUMENT', 'ANSWER', 'CLARIFICATION', 'INSPECTION', 'MEDICAL'];

    public function __construct(private AuditWriter $audit, private OutboxWriter $outbox, private ProposalService $proposals) {}

    public function assign(UnderwritingCase $c, User $assignee, User $actor): UnderwritingCase
    {
        if (! in_array($c->status, UnderwritingCaseMachine::TRANSITIONS['assign'], true)) {
            throw ValidationException::withMessages(['status' => __('wave3.case_closed')]);
        }
        $c->update(['assigned_to' => $assignee->id]);
        $this->audit->record('underwriting.case.assigned', 'underwriting_case', $c->id, ['assigned_to' => $assignee->id, 'state' => UnderwritingCaseMachine::canonicalState($c)]);

        return $c->refresh();
    }

    public function startReview(UnderwritingCase $c, User $actor): UnderwritingCase
    {
        UnderwritingCaseMachine::assertCan($c, 'start_review');
        $c->update(['status' => 'IN_REVIEW', 'assigned_to' => $c->assigned_to ?? $actor->id, 'review_started_at' => now()]);
        $this->audit->record('underwriting.case.review_started', 'underwriting_case', $c->id, []);

        return $c->refresh();
    }

    public function readyForDecision(UnderwritingCase $c, User $actor, ?string $note = null): UnderwritingCase
    {
        UnderwritingCaseMachine::assertCan($c, 'ready_for_decision');
        if ($c->referrals()->where('status', 'OPEN')->exists()) {
            throw ValidationException::withMessages(['referrals' => __('wave3.open_referrals')]);
        }
        $c->update(['status' => 'DECISION_PENDING', 'decision_pending_at' => now()]);
        $this->audit->record('underwriting.case.decision_pending', 'underwriting_case', $c->id, ['recommendation' => $c->recommendation], $note);

        return $c->refresh();
    }

    /**
     * WF-019: ask the proposal originator for exact items. Items default to those the latest evaluation requested.
     *
     * @param  list<array{code: string, description: string, kind?: string}>  $items
     */
    public function requestInformation(UnderwritingCase $c, array $items, ?string $message, User $actor): UnderwritingCase
    {
        UnderwritingCaseMachine::assertCan($c, 'request_information');
        $items = array_values(array_map(fn (array $i) => array_filter([
            'code' => strtoupper(trim((string) ($i['code'] ?? ''))), 'description' => trim((string) ($i['description'] ?? '')),
            'kind' => isset($i['kind']) ? strtoupper((string) $i['kind']) : 'CLARIFICATION', 'mandatory' => (bool) ($i['mandatory'] ?? true),
        ], fn ($v) => $v !== ''), $items));
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'List at least one exact item of information to provide.']);
        }
        foreach ($items as $n => $i) {
            if (! isset($i['code'], $i['description']) || ! preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $i['code']) || ! in_array($i['kind'], self::ITEM_KINDS, true)) {
                throw ValidationException::withMessages(["items.{$n}" => 'Each item needs an UPPER_SNAKE code, a description and a kind ('.implode(', ', self::ITEM_KINDS).').']);
            }
        }

        return DB::transaction(function () use ($c, $items, $message, $actor): UnderwritingCase {
            $c = UnderwritingCase::whereKey($c->id)->lockForUpdate()->firstOrFail();
            UnderwritingCaseMachine::assertCan($c, 'request_information');
            // The requester owns the case: the proposer's resubmission returns it to them (ProposalService::resubmit).
            $c->update(['status' => $c->status === 'DECISION_PENDING' ? 'IN_REVIEW' : $c->status, 'assigned_to' => $c->assigned_to ?? $actor->id]);
            $p = $this->proposals->requestInformation($c->proposal, $items, $message, $actor);
            $c->refresh();
            if ($c->status !== 'AWAITING_INFORMATION') {
                $c->update(['status' => 'AWAITING_INFORMATION']);
            }
            $this->audit->record('underwriting.information.requested', 'underwriting_case', $c->id, [
                'request_id' => $p->information_request['id'] ?? null, 'items' => array_column($items, 'code'), 'requested_from' => $p->created_by,
            ], $message);

            return $c->refresh();
        });
    }

    public function resolveReferral(UnderwritingReferralTask $t, string $notes, User $actor): UnderwritingReferralTask
    {
        if ($t->status !== 'OPEN') {
            throw ValidationException::withMessages(['status' => __('wave3.referral_closed')]);
        }
        $t->update(['status' => 'RESOLVED', 'resolution_notes' => $notes, 'resolved_by' => $actor->id, 'resolved_at' => now()]);
        $this->audit->record('underwriting.referral.resolved', 'underwriting_referral', $t->id, []);

        return $t->refresh();
    }

    /** @param array{decision: string, reason_code: string, notes: string, conditions?: array<mixed>} $d */
    public function decide(UnderwritingCase $c, array $d, User $actor): Proposal
    {
        [$proposalDecision, $outcome] = self::DECISIONS[$d['decision']] ?? throw ValidationException::withMessages(['decision' => "Unknown decision {$d['decision']}."]);
        if ($outcome === 'CONDITIONAL' && empty($d['conditions'])) {
            throw ValidationException::withMessages(['conditions' => 'A conditional acceptance lists its conditions.']);
        }

        return DB::transaction(function () use ($c, $d, $actor, $proposalDecision, $outcome) {
            $c = UnderwritingCase::whereKey($c->id)->lockForUpdate()->firstOrFail();
            if (! in_array($c->status, UnderwritingCaseMachine::TRANSITIONS['decide'], true)) {
                throw ValidationException::withMessages(['status' => __('wave3.case_closed')]);
            }
            if ($c->referrals()->where('status','OPEN')->exists()) {
                throw ValidationException::withMessages(['referrals' => __('wave3.open_referrals')]);
            }
            $decision = UnderwritingDecision::create(['underwriting_case_id' => $c->id, 'decision' => $proposalDecision, 'outcome' => $outcome, 'reason_code' => $d['reason_code'],
                'notes' => $d['notes'], 'conditions' => $d['conditions'] ?? [], 'decided_by' => $actor->id, 'decided_at' => now(),
                'engine_evaluation_id' => $c->engine_evaluation_id, 'system_recommendation' => $c->recommendation]);
            $c->update(['status' => 'DECIDED', 'outcome' => $outcome, 'assigned_to' => $c->assigned_to ?? $actor->id]);
            // Batch 7B: the proposal status moves only through the proposal machine (ProposalService), never directly.
            $p = $this->proposals->applyUnderwritingDecision($c->proposal, $proposalDecision, $d['reason_code'], $decision->id, $actor);
            $overrides = $c->recommendation !== null && ! self::agrees($c->recommendation, $outcome);
            $this->audit->record('underwriting.decided', 'proposal', $p->id, ['decision' => $proposalDecision, 'outcome' => $outcome,
                'system_recommendation' => $c->recommendation, 'overrides_recommendation' => $overrides, 'engine_evaluation_id' => $c->engine_evaluation_id], $d['reason_code']);
            $this->outbox->record('underwriting.decided', 'proposal', $p->id, ['proposal_id' => $p->id, 'decision' => $proposalDecision, 'outcome' => $outcome, 'underwriting_case_id' => $c->id]);

            return $p->refresh();
        });
    }

    /** Whether a human outcome follows the system recommendation (for override reporting). */
    public static function agrees(string $recommendation, string $outcome): bool
    {
        return match ($recommendation) {
            'AUTO_ACCEPT' => $outcome === 'APPROVED',
            'CONDITIONAL_ACCEPT' => $outcome === 'CONDITIONAL',
            'DECLINE' => $outcome === 'DECLINED',
            default => true, // REFER / REQUEST_INFO / INSPECTION / MEDICAL leave the outcome to the underwriter
        };
    }
}
