<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Application\Kyc\KycGate;
use App\Application\Underwriting\Proposal\ProposalDocumentRequirements;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\Proposal;
use App\Models\UnderwritingCase;
use App\Models\UnderwritingDecision;

/**
 * REQ-PRP-004 (PRE §37) — POLICY_ISSUABLE: a proposal may become a policy only when it is
 *   approved (PAYMENT_PENDING, or legacy APPROVED)            → PROPOSAL_NOT_APPROVED
 *   + an approving underwriting decision (STP or human), no open referral → UNDERWRITING_*
 *   + the payment condition (first instalment / single premium succeeded and reconciled) → PAYMENT_*
 *   + every mandatory proposal document accepted                → DOCUMENT_*
 *   + KYC when the tenant gate enforces it                      → KYC_REQUIRED
 *   + no policy already issued for it                           → ALREADY_ISSUED
 * Read-only evaluation with explicit blockers; issuance (PolicyIssuanceService, batch 7) calls assertIssuable().
 */
final class PolicyIssuabilityService
{
    public function __construct(private readonly ProposalDocumentRequirements $documents, private readonly KycGate $kyc) {}

    /** @return array{issuable: bool, blockers: list<string>, payment_intent_id: ?string, underwriting_decision_id: ?string, first_instalment_minor: int} */
    public function evaluate(Proposal $p): array
    {
        $blockers = [];
        if (! in_array($p->status, ['PAYMENT_PENDING', 'APPROVED'], true)) {
            $blockers[] = 'PROPOSAL_NOT_APPROVED:'.$p->status;
        }

        $case = UnderwritingCase::where('proposal_id', $p->id)->latest('created_at')->first();
        $decision = $case ? UnderwritingDecision::where('underwriting_case_id', $case->id)->latest('decided_at')->first() : null;
        $counterAccepted = $decision?->decision === 'COUNTEROFFERED' && ($p->terms_snapshot['counter_offer_decision_id'] ?? null) === $decision->id;
        // Straight-through: a proposal the proposal machine moved to PAYMENT_PENDING (auto_approve / approve /
        // accept_counteroffer) without any underwriting case on record (pre-case / rules-only STP flows) is approved.
        $stpWithoutCase = $case === null && $p->status === 'PAYMENT_PENDING';
        if (! $stpWithoutCase && ($decision === null || ! ($decision->decision === 'APPROVED' || $counterAccepted))) {
            $blockers[] = 'UNDERWRITING_NOT_APPROVED';
        }
        if ($case && $case->referrals()->where('status', 'OPEN')->exists()) {
            $blockers[] = 'UNDERWRITING_REFERRAL_OPEN';
        }

        $firstDue = (int) ($p->cover_terms['schedule'][0]['amount_minor'] ?? $p->terms_snapshot['total_minor'] ?? 0);
        $payment = PaymentIntentRecord::where('proposal_id', $p->id)->where('status', 'SUCCEEDED')->latest('updated_at')->first();
        if ($payment === null) {
            $blockers[] = 'PAYMENT_NOT_RECEIVED';
        } else {
            if ($payment->reconciled_at === null) {
                $blockers[] = 'PAYMENT_NOT_RECONCILED';
            }
            if ((int) $payment->amount_minor < $firstDue) {
                $blockers[] = 'PAYMENT_INSUFFICIENT';
            }
        }

        foreach ($this->documents->for($p) as $d) {
            if ($d['mandatory'] && $d['status'] !== 'ACCEPTED') {
                $blockers[] = 'DOCUMENT_'.$d['status'].':'.$d['code'];
            }
        }

        if ($this->kyc->mode($p->tenant_id) === 'ENFORCE' && ! ($st = $this->kyc->status($p->tenant_id, $p->party_id))['verified']) {
            $blockers[] = 'KYC_REQUIRED:'.$st['reason'];
        }

        if (Policy::where('proposal_id', $p->id)->exists()) {
            $blockers[] = 'ALREADY_ISSUED';
        }

        return ['issuable' => $blockers === [], 'blockers' => $blockers, 'payment_intent_id' => $payment?->id, 'underwriting_decision_id' => $decision?->id, 'first_instalment_minor' => $firstDue];
    }

    public function assertIssuable(Proposal $p): void
    {
        $r = $this->evaluate($p);
        if (! $r['issuable']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['proposal' => array_map(fn ($b) => "Not issuable: {$b}", $r['blockers'])]);
        }
    }
}
