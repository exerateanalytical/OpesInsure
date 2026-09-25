<?php

declare(strict_types=1);

namespace App\Application\Underwriting;

use App\Domain\Shared\StateMachine\StateMachineDefinition;

/**
 * REQ-PRP-001 — the proposal machine on the shared StateMachineEngine (REQ-WFL-001).
 *
 * Blueprint states (BP II Proposal / WRS WF-015/016): draft → submitted → reviewing → information_required → resubmitted
 * → approved / declined. proposals.status stores the code below; the blueprint name is exposed through blueprintState().
 * Codes the live app (1.3.0) already reads are kept as-is:
 *   DRAFT, DISCLOSURES_PENDING, DOCUMENTS_PENDING  → blueprint DRAFT (answering questions / collecting documents)
 *   UNDER_REVIEW                                   → blueprint REVIEWING
 *   PAYMENT_PENDING (and legacy APPROVED)          → blueprint APPROVED (approved, awaiting the payment condition)
 *   COUNTEROFFERED                                 → approval on revised terms, awaiting the customer
 * WITHDRAWN (customer) and DECLINED (insurer) are terminal. Quote ≠ Proposal ≠ Policy (LOCK-005): the proposal only
 * copies the accepted offer's terms; issuance creates the policy separately.
 */
final class ProposalMachine
{
    public const NAME = 'proposal';

    public const PRE_SUBMISSION = ['DRAFT', 'DISCLOSURES_PENDING', 'DOCUMENTS_PENDING'];

    public const IN_UNDERWRITING = ['SUBMITTED', 'UNDER_REVIEW', 'RESUBMITTED'];

    public const TERMINAL = ['DECLINED', 'WITHDRAWN'];

    /** Answers may change in these states (re-attestation required afterwards). */
    public const ANSWERABLE = ['DRAFT', 'DISCLOSURES_PENDING', 'DOCUMENTS_PENDING', 'INFORMATION_REQUIRED'];

    public const BLUEPRINT = [
        'DRAFT' => 'DRAFT', 'DISCLOSURES_PENDING' => 'DRAFT', 'DOCUMENTS_PENDING' => 'DRAFT',
        'SUBMITTED' => 'SUBMITTED', 'UNDER_REVIEW' => 'REVIEWING', 'INFORMATION_REQUIRED' => 'INFORMATION_REQUIRED', 'RESUBMITTED' => 'RESUBMITTED',
        'COUNTEROFFERED' => 'COUNTEROFFERED', 'APPROVED' => 'APPROVED', 'PAYMENT_PENDING' => 'APPROVED',
        'DECLINED' => 'DECLINED', 'WITHDRAWN' => 'WITHDRAWN',
    ];

    private static ?StateMachineDefinition $machine = null;

    public static function blueprintState(string $status): string
    {
        return self::BLUEPRINT[$status] ?? $status;
    }

    public static function definition(): StateMachineDefinition
    {
        $review = self::IN_UNDERWRITING;
        $decidable = [...$review, 'INFORMATION_REQUIRED'];

        return self::$machine ??= StateMachineDefinition::fromArray([
            'name' => self::NAME, 'version' => 1, 'subject_type' => 'proposal',
            'states' => [
                'DRAFT' => ['initial' => true, 'label' => 'Draft'],
                'DISCLOSURES_PENDING' => ['label' => 'Draft — questions'],
                'DOCUMENTS_PENDING' => ['label' => 'Draft — documents and declarations'],
                'SUBMITTED' => ['label' => 'Submitted'],
                'UNDER_REVIEW' => ['label' => 'Reviewing'],
                'INFORMATION_REQUIRED' => ['label' => 'Information required'],
                'RESUBMITTED' => ['label' => 'Resubmitted'],
                'COUNTEROFFERED' => ['label' => 'Counter-offered'],
                'APPROVED' => ['label' => 'Approved'],
                'PAYMENT_PENDING' => ['label' => 'Approved — payment pending'],
                'DECLINED' => ['terminal' => true, 'label' => 'Declined'],
                'WITHDRAWN' => ['terminal' => true, 'label' => 'Withdrawn'],
            ],
            'transitions' => [
                ['event' => 'open', 'from' => ['DRAFT'], 'to' => 'DISCLOSURES_PENDING'],
                ['event' => 'complete_questions', 'from' => ['DRAFT', 'DISCLOSURES_PENDING'], 'to' => 'DOCUMENTS_PENDING', 'failure_path' => 'stay; list the unanswered required questions'],
                ['event' => 'submit', 'from' => ['DOCUMENTS_PENDING'], 'to' => 'SUBMITTED', 'guards' => ['proposal.attested', 'proposal.documents_complete'],
                    'side_effects' => ['kyc_gate', 'completeness_gate(BIND)', 'immutable_snapshot', 'underwriting_case', 'outbox:proposal.submitted'],
                    'failure_path' => 'stay DOCUMENTS_PENDING; show missing declarations / documents / KYC / data'],
                ['event' => 'refer', 'from' => ['SUBMITTED', 'RESUBMITTED'], 'to' => 'UNDER_REVIEW', 'domain_event' => 'proposal.referred', 'notification' => 'proposal.under_review'],
                ['event' => 'auto_approve', 'from' => ['SUBMITTED'], 'to' => 'PAYMENT_PENDING', 'domain_event' => 'proposal.approved', 'notification' => 'proposal.approved',
                    'failure_path' => 'referral flags present: refer instead'],
                ['event' => 'request_information', 'from' => $review, 'to' => 'INFORMATION_REQUIRED', 'domain_event' => 'proposal.information_requested',
                    'notification' => 'proposal.information_required'],
                ['event' => 'resubmit', 'from' => ['INFORMATION_REQUIRED'], 'to' => 'RESUBMITTED', 'guards' => ['proposal.attested', 'proposal.documents_complete'],
                    'side_effects' => ['immutable_snapshot'], 'domain_event' => 'proposal.resubmitted', 'failure_path' => 'stay INFORMATION_REQUIRED'],
                ['event' => 'approve', 'from' => $decidable, 'to' => 'PAYMENT_PENDING', 'domain_event' => 'proposal.approved', 'notification' => 'proposal.approved'],
                ['event' => 'counteroffer', 'from' => $decidable, 'to' => 'COUNTEROFFERED', 'domain_event' => 'proposal.counteroffered', 'notification' => 'proposal.counteroffered'],
                ['event' => 'decline', 'from' => $decidable, 'to' => 'DECLINED', 'domain_event' => 'proposal.declined', 'notification' => 'proposal.declined'],
                ['event' => 'accept_counteroffer', 'from' => ['COUNTEROFFERED'], 'to' => 'PAYMENT_PENDING', 'side_effects' => ['outbox:proposal.counteroffer.accepted']],
                ['event' => 'decline_counteroffer', 'from' => ['COUNTEROFFERED'], 'to' => 'WITHDRAWN', 'side_effects' => ['outbox:proposal.counteroffer.declined']],
                ['event' => 'withdraw', 'from' => [...self::PRE_SUBMISSION, ...$review, 'INFORMATION_REQUIRED', 'COUNTEROFFERED'], 'to' => 'WITHDRAWN',
                    'domain_event' => 'proposal.withdrawn'],
            ],
        ]);
    }
}
