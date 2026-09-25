<?php

declare(strict_types=1);

namespace App\Application\Events\Catalogue;

use InvalidArgumentException;

/**
 * REQ-ARC-004 canonical domain event catalogue.
 *
 * Canonical name = dotted `<domain>.<noun>[.<verb>]` (the form the code already emits to outbox_messages).
 * WRS/PRE PascalCase names are aliases (TRACEABILITY §3 "Domain event names").
 * `emitted` = a producer exists today; false = defined, producer arrives in the owning domain wave.
 * Definitions only: no producer is changed here.
 */
final class DomainEventCatalogue
{
    /** @var array<string,EventDefinition>|null */
    private static ?array $byName = null;

    /** @var array<string,string>|null alias => name */
    private static ?array $byAlias = null;

    /** @return list<EventDefinition> */
    public static function definitions(): array
    {
        $W = EventDefinition::SOURCE_WRS;
        $P = EventDefinition::SOURCE_PRE;
        $C = EventDefinition::SOURCE_CODE;
        $E = EventDefinition::SOURCE_ENGINE;
        $d = static fn (string $n, string $agg, string $desc, array $aliases = [], array $src = [], bool $emitted = false) => new EventDefinition($n, $agg, $desc, $aliases, $src ?: [$C], $emitted);

        return [
            // --- WRS 26 required events (+ PRE aliases where the same fact) ---
            $d('customer.registered', 'customer', 'Customer account registered.', ['CustomerRegistered'], [$W], true),
            $d('kyc_submission.submitted', 'kyc_submission', 'KYC submission sent for review.', ['KYCSubmitted'], [$W], true),
            $d('kyc_submission.approved', 'kyc_submission', 'KYC submission approved by reviewer.', ['KYCApproved'], [$W], true),
            // REQ-KYC-001..003 (App\Application\Kyc\KycService)
            $d('kyc_submission.rejected', 'kyc_submission', 'KYC submission rejected (checker confirmed).', [], [$C], true),
            $d('kyc_submission.information_requested', 'kyc_submission', 'Reviewer asked the customer for more KYC information.', [], [$C], true),
            $d('kyc_submission.remediation_requested', 'kyc_submission', 'KYC refresh/remediation opened; original submission retained.', [], [$C], true),
            $d('kyc_submission.expired', 'kyc_submission', 'Approved KYC reached its expiry.', [], [$C], true),
            $d('kyc_submission.rescreen_started', 'kyc_submission', 'Manual (audited) rescreening round opened on an approved KYC.', [], [$C], true),
            $d('kyc_submission.rescreen_match', 'kyc_submission', 'Rescreening of an approved KYC recorded a possible / confirmed match.', [], [$C], true),
            $d('quote.rated', 'quote', 'Quote premium calculated by the rating engine.', ['QuoteCalculated', 'QuoteRated'], [$W, $P], true),
            $d('quote.offer.accepted', 'quote', 'Customer accepted the quote offer.', ['QuoteAccepted'], [$W], true),
            $d('proposal.submitted', 'proposal', 'Proposal submitted for underwriting.', ['ProposalSubmitted'], [$W], true),
            $d('underwriting.decided', 'underwriting_case', 'Underwriting decision recorded (any outcome).', ['UnderwritingDecided'], [$P], true),
            $d('underwriting.approved', 'underwriting_case', 'Underwriting approved (outcome-specific view of underwriting.decided).', ['UnderwritingApproved'], [$W]),
            $d('payment.requested', 'payment_intent', 'Payment intent created and initiated with a provider.', ['PaymentInitiated'], [$W], true),
            $d('payment.succeeded', 'payment_intent', 'Payment confirmed successful. Today carried by payment.status.changed.', ['PaymentSucceeded'], [$W]),
            $d('payment.failed', 'payment_intent', 'Payment failed. Today carried by payment.status.changed.', ['PaymentFailed'], [$W]),
            $d('policy.issued', 'policy', 'Policy issued.', ['PolicyIssued'], [$W], true),
            $d('policy.activated', 'policy', 'Policy cover became active.', ['PolicyActivated'], [$W]),
            $d('certificate.issued', 'certificate', 'Attestation/certificate generated.', ['AttestationGenerated'], [$W], true),
            $d('policy.endorsement.issued', 'policy', 'Endorsement issued on a policy.', ['EndorsementIssued'], [$W, $C], true),
            $d('renewal.due', 'policy', 'Policy entered its renewal window.', ['RenewalDue'], [$W], true),
            $d('renewal.completed', 'policy', 'Policy renewed.', ['PolicyRenewed'], [$W], true),
            $d('claim.fnol.submitted', 'claim', 'Claim reported (FNOL).', ['ClaimReported'], [$W], true),
            $d('claim.evidence.attached', 'claim', 'Claim evidence received.', ['ClaimEvidenceReceived'], [$W], true),
            $d('claim.assigned', 'claim', 'Claim assigned to a handler.', ['ClaimAssigned'], [$W], true),
            $d('claim.decision.approved', 'claim', 'Claim approved.', ['ClaimApproved'], [$W], true),
            $d('claim.decision.rejected', 'claim', 'Claim rejected/declined.', ['ClaimRejected'], [$W]),
            $d('claim.payment.paid', 'claim', 'Claim settlement paid.', ['ClaimSettled'], [$W], true),
            $d('refund.approved', 'refund', 'Refund approved.', ['RefundApproved'], [$W], true),
            // Batch 9-6 REQ-PAY-009 / WF-063 refund engine (App\Application\Finance\Refunds\RefundEngine)
            $d('refund.candidate_created', 'refund', 'Refund candidate raised (cancellation, endorsement, issuance exception or manual).', [], [$C], true),
            $d('refund.calculated', 'refund', 'Refund amount calculated by the maker.', [], [$C], true),
            $d('refund.reviewed', 'refund', 'Refund calculation reviewed and sent for approval.', [], [$C], true),
            $d('refund.rejected', 'refund', 'Refund rejected before payout.', [], [$C], true),
            $d('refund.paid', 'refund', 'Approved refund paid out to the customer.', [], [$C], true),
            $d('refund.reconciled', 'refund', 'Refund payout matched to the bank / provider statement.', [], [$C], true),
            // Batch 9-6 REQ-PAY-011 mobile-money clearing (App\Application\Finance\Clearing\ClearingService)
            $d('payment.clearing.settled', 'clearing_batch', 'Provider settlement batch credited by the bank.', [], [$C], true),
            $d('payment.clearing.reconciled', 'clearing_batch', 'Provider settlement batch reconciled (matched or variance).', [], [$C], true),
            $d('commission.accrued', 'commission', 'Commission accrued.', ['CommissionAccrued'], [$W], true),
            $d('commission.settled', 'commission', 'Commission settled to the intermediary.', ['CommissionSettled'], [$W]),
            $d('settlement.completed', 'settlement', 'Financial settlement completed (scope UNVERIFIED: WRS gives no definition).', ['SettlementCompleted'], [$W]),

            // --- PRE 15 product events (QuoteRated, UnderwritingDecided aliased above) ---
            $d('catalogue.product.created', 'product', 'Product created.', ['ProductCreated'], [$P], true),
            $d('catalogue.product_version.submitted', 'product_version', 'Product version submitted for approval.', ['ProductVersionSubmitted'], [$P]),
            $d('catalogue.product_version.approved', 'product_version', 'Product version approved.', ['ProductVersionApproved'], [$P], true),
            $d('catalogue.product_version.created', 'product_version', 'New draft product version created (Batch 5A).', ['ProductVersionCreated'], [$P], true),
            $d('catalogue.product_version.reinstated', 'product_version', 'Suspended product version reinstated (Batch 5A).', ['ProductVersionReinstated'], [$P], true),
            $d('catalogue.product.published', 'product_version', 'Product version published.', ['ProductVersionPublished'], [$P], true),
            $d('catalogue.product_version.rejected', 'product_version', 'Product version rejected in governance review (REQ-PRD-007).', ['ProductVersionRejected'], [$P], true),
            $d('catalogue.product_version.governance_stage_changed', 'product_version', 'Product version moved to another PRE §74 governance stage (REQ-PRD-007).', ['ProductGovernanceStageChanged'], [$P], true),
            $d('catalogue.product_version.suspended', 'product_version', 'Product version suspended.', ['ProductVersionSuspended'], [$P], true),
            $d('catalogue.product_version.retired', 'product_version', 'Product version retired.', ['ProductVersionRetired'], [$P], true),
            $d('tariff.approved', 'tariff', 'Tariff approved.', ['TariffApproved'], [$P], true),
            $d('tariff.activated', 'tariff', 'Tariff activated.', ['TariffActivated'], [$P]),
            $d('rules.eligibility.evaluated', 'eligibility', 'Eligibility evaluated.', ['EligibilityEvaluated'], [$P]),
            $d('policy.snapshot.created', 'policy', 'Immutable policy product snapshot created.', ['PolicySnapshotCreated'], [$P]),
            $d('claim.coverage.evaluated', 'claim', 'Claim coverage evaluated.', ['ClaimCoverageEvaluated'], [$P]),
            $d('claim.settlement.calculated', 'claim', 'Claim settlement amount calculated.', ['ClaimSettlementCalculated'], [$P]),
            $d('commission.calculated', 'commission', 'Commission calculated.', ['CommissionCalculated'], [$P], true),

            // --- Already emitted by code, no spec alias ---
            $d('chargeback.resolved', 'chargeback', 'Payment chargeback resolved.', [], [], true),
            $d('claim.carrier_message.queued', 'claim', 'Message to carrier queued for a claim.', [], [], true),
            $d('claim.dispute.resolved', 'claim', 'Claim dispute resolved.', [], [], true),
            // REQ-CLM-010 (App\Application\Claims\Assessment)
            $d('claim.assessment.recorded', 'claim', 'Claim assessment (recommendation) recorded.', [], [], true),
            $d('claim.assessment.accepted', 'claim', 'Claim assessment accepted by a reviewer.', [], [], true),
            $d('claim.assessment.rejected', 'claim', 'Claim assessment rejected by a reviewer.', [], [], true),
            $d('claim.investigation.opened', 'claim', 'Claim investigation opened (case engine).', [], [], true),
            $d('claim.investigation.indicators_attached', 'claim', 'Fraud indicators attached to a claim investigation.', [], [], true),
            $d('claim.investigation.concluded', 'claim', 'Claim investigation concluded with an outcome.', [], [], true),
            $d('claim.payment.approved', 'claim', 'Claim payment approved.', [], [], true),
            $d('claim.payment.reversed', 'claim', 'Claim payment reversed.', [], [], true),
            $d('claim.reserve.approved', 'claim', 'Claim reserve approved.', [], [], true),
            $d('claim.transitioned', 'claim', 'Generic claim status transition.', [], [], true),
            $d('commission.clawed_back', 'commission', 'Commission clawed back.', [], [], true),
            $d('commission.rule.approved', 'commission_rule', 'Commission rule approved.', [], [], true),
            // REQ-COM-003 (Batch 10-3) commission statements: adjustments (maker-checker), disputes, payable
            $d('commission.statement.adjustment_proposed', 'partner_statement', 'Commission statement adjustment proposed (maker).', [], [], true),
            $d('commission.statement.adjustment_approved', 'partner_statement', 'Commission statement adjustment approved (checker).', [], [], true),
            $d('commission.statement.adjustment_rejected', 'partner_statement', 'Commission statement adjustment rejected (checker).', [], [], true),
            $d('commission.statement.disputed', 'partner_statement', 'Commission statement disputed; dispute case opened.', [], [], true),
            $d('commission.statement.dispute_resolved', 'partner_statement', 'Commission statement dispute resolved; statement back to DRAFT.', [], [], true),
            $d('commission.payable.opened', 'partner_statement', 'Commission PAYABLE obligation opened for an approved statement.', [], [], true),
            $d('customer.attribution.changed', 'customer', 'Customer attribution changed.', [], [], true),
            $d('partner.portfolio.transferred', 'partner', 'Portfolio transferred between intermediaries (REQ-CRM-003).', [], [], true),
            // REQ-POL-009 (Batch 8-7) policy portfolio transfer + portability export
            $d('policy.portfolio_transfer.requested', 'policy_portfolio_transfer', 'Policy portfolio transfer requested (maker).', [], [], true),
            $d('policy.portfolio_transfer.approved', 'policy_portfolio_transfer', 'Policy portfolio transfer approved (checker).', [], [], true),
            $d('policy.portfolio_transfer.rejected', 'policy_portfolio_transfer', 'Policy portfolio transfer rejected (checker).', [], [], true),
            $d('policy.portfolio_transfer.consent_requested', 'policy', 'Policyholder consent requested for a policy transfer.', [], [], true),
            $d('policy.servicing.transferred', 'policy', 'Policy servicing intermediary/carrier changed; customer notice due.', [], [], true),
            $d('policy.portability.exported', 'policy', 'Policy portability export pack produced.', [], [], true),
            $d('policy.beneficiaries.changed', 'policy', 'Policy beneficiary set replaced (REQ-CRM-004).', [], [], true),
            $d('customer.attribution.locked', 'customer', 'Customer attribution locked.', [], [], true),
            $d('customer.status.changed', 'customer', 'Customer status changed.', [], [], true),
            $d('disclosure.schema.approved', 'disclosure_schema', 'Disclosure schema approved.', [], [], true),
            $d('document.requirement.approved', 'document_requirement', 'Document requirement approved.', [], [], true),
            // Batch 8-9 REQ-DOC-009/010/012 (App\Application\Documents\Intake|Retention|Signatures)
            $d('document.intake.received', 'document_intake', 'Incoming document taken in (channel recorded, register suggestion attached).', [], [], true),
            $d('document.intake.classified', 'document_intake', 'Incoming document classified against the document register.', [], [], true),
            $d('document.intake.exception', 'document_intake', 'Incoming document could not be classified; DOC_INTAKE_EXCEPTION case opened.', [], [], true),
            $d('document.legal_hold.placed', 'legal_hold', 'Legal hold placed on a subject (blocks destruction).', [], [], true),
            $d('document.legal_hold.released', 'legal_hold', 'Legal hold released (checker different from placer).', [], [], true),
            $d('document.retention_schedule.approved', 'retention_schedule', 'Retention schedule approved and made ACTIVE.', [], [], true),
            $d('document.destruction.requested', 'document', 'Document destruction requested; DOCUMENT_DESTRUCTION case opened.', [], [], true),
            $d('document.destruction.decided', 'document', 'Document destruction request approved/rejected/blocked.', [], [], true),
            $d('document.destroyed', 'document', 'Document content destroyed; tombstone kept.', [], [], true),
            // Batch 9-7 REQ-PAY-010/013 (App\Application\Finance\Cashier|Fx)
            $d('cashier.session.opened', 'cashier_session', 'Cashier session opened with a float (one open session per cashier per branch).', [], [], true),
            $d('cashier.collection.recorded', 'cashier_collection', 'Cash or cheque collection recorded in a cashier session.', [], [], true),
            $d('cashier.session.closed', 'cashier_session', 'Cashier session closed with counted cash and variance.', [], [], true),
            $d('cashier.session.approved', 'cashier_session', 'Closed cashier session approved by a supervisor (not the cashier).', [], [], true),
            $d('cashier.session.rejected', 'cashier_session', 'Closed cashier session rejected by a supervisor.', [], [], true),
            $d('fx.rate.recorded', 'fx_rate', 'Immutable FX rate recorded (append-only).', [], [], true),
            $d('document.signature.requested', 'signature_request', 'Document sent for e-signature.', [], [], true),
            $d('document.signature.signed', 'signature_request', 'One signer signed.', [], [], true),
            $d('document.signature.completed', 'signature_request', 'All signers signed.', [], [], true),
            $d('document.signature.declined', 'signature_request', 'A signer declined; request ended.', [], [], true),
            $d('ledger.event.posted', 'ledger', 'Ledger journal posted.', [], [], true),
            // Batch 10-7 REQ-ACC-002 (App\Application\Ledger\Journals\ManualJournalService)
            $d('ledger.journal.drafted', 'journal', 'Manual journal drafted.', [], [], true),
            $d('ledger.journal.validated', 'journal', 'Manual journal validated (balanced, active accounts, open period).', [], [], true),
            $d('ledger.journal.approved', 'journal', 'Manual journal approved by a checker other than the maker.', [], [], true),
            $d('ledger.journal.rejected', 'journal', 'Manual journal sent back to DRAFT.', [], [], true),
            $d('ledger.journal.posted', 'journal', 'Approved manual journal posted.', [], [], true),
            $d('ledger.journal.reversed', 'journal', 'Journal reversed by a mirror journal.', [], [], true),
            $d('notification.delivery.requested', 'notification', 'Notification delivery requested.', [], [], true),
            $d('notification.delivery.sent', 'notification', 'Notification delivered to provider.', [], [], true),
            $d('partner.licence.decided', 'partner', 'Partner licence decision recorded.', [], [], true),
            $d('partner.licence.submitted', 'partner', 'Partner licence submitted.', [], [], true),
            $d('partner.payout.paid', 'partner_payout', 'Partner payout paid.', [], [], true),
            $d('partner.payout.reversed', 'partner_payout', 'Partner payout reversed.', [], [], true),
            $d('partner.statement.approved', 'partner_statement', 'Partner statement approved.', [], [], true),
            // REQ-STL-002 / REQ-DUP-008 carrier bordereau (App\Application\FinancialDistribution\BordereauService)
            $d('bordereau.prepared', 'bordereau', 'Carrier bordereau prepared with per-type items for a period.', [], [], true),
            $d('bordereau.approved', 'bordereau', 'Carrier bordereau approved (checker).', [], [], true),
            $d('bordereau.submitted', 'bordereau', 'Carrier bordereau submitted to the carrier.', [], [], true),
            $d('bordereau.acknowledged', 'bordereau', 'Carrier acknowledged the bordereau.', [], [], true),
            $d('bordereau.rejected', 'bordereau', 'Carrier rejected the bordereau.', [], [], true),
            $d('party.created', 'party', 'Party created.', [], [], true),
            // REQ-PTY-002/003/004 golden record (App\Application\Customers\Roles|Relationships|Matching)
            $d('party.role_assigned', 'party', 'Explicit bitemporal party role recorded (LOCK-006).', [], [], true),
            $d('party.relationship_added', 'party', 'Party relationship recorded (household, employer, group, corporate).', [], [], true),
            $d('party.ownership_added', 'party', 'Ownership interest recorded (UBO graph).', [], [], true),
            $d('party.merged', 'party', 'Duplicate party merged into a survivor after maker-checker approval.', [], [], true),
            $d('party.unmerged', 'party', 'Applied party merge reversed.', [], [], true),
            $d('payment.authorization.requested', 'payment_intent', 'Payment authorization requested from payer.', [], [], true),
            $d('payment.collection_mode.assigned', 'payment_intent', 'Payment execution/collection mode (who collects, who holds funds, account credited) pinned on a payment (REQ-PAY-014).', [], [], true),
            $d('payment.retry.requested', 'payment_intent', 'Failed payment retried as a new attempt under the same payment intent/obligation (REQ-PAY-008).', [], [], true),
            $d('payment.retry.exhausted', 'payment_intent', 'Payment retry refused: attempt limit reached (REQ-PAY-008).', [], [], true),
            $d('payment.status.changed', 'payment_intent', 'Payment status changed (carries successful/failed today).', [], [], true),
            $d('payment.allocated', 'payment_intent', 'Payment allocated to premium components / obligations (REQ-PAY-004).', [], [], true),
            $d('payment.allocation.reversed', 'payment_intent', 'Payment allocation run reversed by reversal rows (REQ-PAY-004).', [], [], true),
            $d('finance.allocation_rule.published', 'allocation_rule_version', 'New tenant allocation-order rule version published (REQ-PAY-004).', [], [], true),
            $d('finance.premium_components.recorded', 'policy', 'Premium components recorded for a policy (REQ-PAY-005).', [], [], true),
            $d('finance.premium_component.cancelled', 'policy', 'Premium component cancelled (REQ-PAY-005).', [], [], true),
            $d('finance.premium_component.written_off', 'policy', 'Premium component written off (REQ-PAY-005).', [], [], true),
            $d('policy.issuance.rejected', 'policy', 'Policy issuance rejected.', [], [], true),
            $d('policy.issuance.requested', 'policy', 'Policy issuance requested.', [], [], true),
            $d('policy.service.approved', 'policy_service_request', 'Policy servicing request approved.', [], [], true),
            $d('policy.service.payment_requested', 'policy_service_request', 'Payment requested for a servicing change.', [], [], true),
            $d('policy.service.rejected', 'policy_service_request', 'Policy servicing request rejected.', [], [], true),
            // REQ-POL-006 (App\Application\Policies\Suspension\PolicySuspensionService)
            $d('policy.suspended', 'policy', 'Policy cover suspended (manual, premium default, compliance or system).', ['PolicySuspended'], [], true),
            $d('policy.reinstatement.requested', 'policy', 'Reinstatement of a suspended policy requested (queued as a POLICY_REINSTATEMENT case).', [], [], true),
            $d('policy.reinstatement.rejected', 'policy', 'Reinstatement request rejected; policy stays suspended.', [], [], true),
            $d('policy.reinstated', 'policy', 'Suspended policy reinstated to ACTIVE.', ['PolicyReinstated'], [], true),
            // REQ-CAN-001 cancellation machine (App\Application\Policies\Cancellation\CancellationService)
            $d('policy.cancellation.requested', 'policy', 'Policy cancellation requested; notice served (WF-044).', [], [], true),
            $d('policy.cancellation.reviewed', 'policy', 'Policy cancellation reviewed, awaiting decision.', [], [], true),
            $d('policy.cancellation.rejected', 'policy', 'Policy cancellation rejected; policy back to ACTIVE.', [], [], true),
            $d('policy.cancelled', 'policy', 'Policy cancelled: refund requested, documents revoked (WF-045).', ['PolicyCancelled'], [], true),
            // REQ-POL-008 / REQ-POL-010 (App\Application\Policies\Lapse — premium-cover sweep + recovery, WF-083)
            $d('policy.premium.grace_started', 'policy', 'Overdue instalment entered its premium-cover grace window.', [], [], true),
            $d('policy.premium.defaulted', 'policy', 'Overdue instalment defaulted (grace elapsed or NO_COVER / COVER_SUSPENDED rule).', [], [], true),
            $d('policy.premium.suspended', 'policy', 'Policy suspended on premium default (SUSPEND_ON_DEFAULT).', [], [], true),
            $d('policy.premium.lapsed', 'policy', 'Defaulted instalment lapsed after the rule lapse_after_days.', [], [], true),
            $d('policy.premium.instalment_settled', 'policy', 'Instalment paid in full or waived.', [], [], true),
            // Batch 10-9 — REQ-ACC-004 (App\Application\Ledger\Technical)
            $d('technical.actuarial_import.created', 'technical_actuarial_import', 'IBNR / life actuarial values imported as a new version awaiting approval (REQ-ACC-004).', [], [], true),
            $d('technical.actuarial_import.approved', 'technical_actuarial_import', 'Actuarial import approved (maker-checker); previous approved version superseded.', [], [], true),
            $d('technical.actuarial_import.rejected', 'technical_actuarial_import', 'Actuarial import rejected.', [], [], true),
            $d('technical.upr.posted', 'technical_upr_posting', 'Period-end UPR computed and its movement posted to the ledger (REQ-ACC-004).', [], [], true),
            // Batch 9-1 — REQ-OBL-001 (App\Application\Finance\Obligations\ObligationService)
            $d('finance.obligation.created', 'financial_obligation', 'Financial obligation (receivable / payable) raised.', [], [], true),
            $d('finance.obligation.settled', 'financial_obligation', 'Financial obligation fully settled.', [], [], true),
            $d('finance.obligation.reopened', 'financial_obligation', 'A settlement on a financial obligation was reversed (obligation reopened).', [], [], true),
            // Batch 10-4 — REQ-STL-001 broker–insurer settlement (App\Application\Settlements\SettlementService)
            $d('settlement.drafted', 'settlement_batch', 'Ledger-calculated carrier settlement drafted.', [], [], true),
            $d('settlement.calculated', 'settlement_batch', 'Settlement calculated from collected premium minus retained commission (carrier payables opened).', [], [], true),
            $d('settlement.review_requested', 'settlement_batch', 'Calculated settlement sent for review.', [], [], true),
            $d('settlement.approved', 'settlement_batch', 'Settlement approved by an independent reviewer (posted to the ledger).', [], [], true),
            $d('settlement.rejected', 'settlement_batch', 'Settlement sent back to draft by the reviewer.', [], [], true),
            $d('settlement.cancelled', 'settlement_batch', 'Settlement cancelled before approval; its payables cancelled.', [], [], true),
            $d('settlement.processing', 'settlement_batch', 'Settlement payment to the carrier submitted.', [], [], true),
            $d('settlement.processing_failed', 'settlement_batch', 'Settlement payment failed; batch returned to approved.', [], [], true),
            $d('settlement.settled', 'settlement_batch', 'Settlement paid; carrier payables settled (posted to the ledger).', [], [], true),
            $d('settlement.reconciled', 'settlement_batch', 'Settled batch reconciled against the bank / carrier statement.', [], [], true),
            $d('policy.recovery.requested', 'policy', 'Recovery opened for a suspended / expired / lapsed policy.', [], [], true),
            $d('policy.recovery.approved', 'policy', 'Recovery approved (maker-checker); policy active again.', [], [], true),
            $d('policy.recovery.rejected', 'policy', 'Recovery case rejected.', [], [], true),
            $d('privacy.consent.changed', 'consent', 'Privacy consent changed.', [], [], true),
            $d('proposal.created', 'proposal', 'Proposal created.', [], [], true),
            // REQ-PRP-001 proposal machine (App\Application\Underwriting\ProposalMachine, published by the StateMachineEngine)
            $d('proposal.referred', 'proposal', 'Submitted proposal referred to an underwriter.', [], [$C], true),
            $d('proposal.approved', 'proposal', 'Proposal approved (awaiting the payment condition).', [], [$C], true),
            $d('proposal.information_requested', 'proposal', 'Underwriter asked the proposer for more information.', [], [$C], true),
            $d('proposal.resubmitted', 'proposal', 'Proposer answered an information request and resubmitted.', [], [$C], true),
            $d('proposal.counteroffered', 'proposal', 'Insurer approved on revised terms (counter-offer).', [], [$C], true),
            $d('proposal.declined', 'proposal', 'Insurer declined the proposal.', [], [$C], true),
            $d('proposal.withdrawn', 'proposal', 'Proposer withdrew the proposal.', [], [$C], true),
            $d('proposal.counteroffer.accepted', 'proposal', 'Proposer accepted the counter-offer terms.', [], [$C], true),
            $d('proposal.counteroffer.declined', 'proposal', 'Proposer declined the counter-offer (proposal withdrawn).', [], [$C], true),
            $d('proposal.declaration.accepted', 'proposal', 'Declaration / attestation / consent accepted on a proposal.', [], [$C], true),
            $d('quote.cancelled', 'quote', 'Quote cancelled.', [], [], true),
            $d('quote.submitted', 'quote', 'Quote submitted.', [], [], true),
            // Batch 6B — REQ-QUO-001…005 quote machine (App\Application\Quotes\QuoteMachine / QuoteService)
            $d('quote.amended', 'quote', 'Quote risk data amended; offers superseded, back to DRAFT.', [], [], true),
            $d('quote.generated', 'quote', 'Quotation generated (number + PDF available).', ['QuoteGenerated'], [], true),
            $d('quote.sent', 'quote', 'Quotation sent/shared to the customer (WF-012).', ['QuoteSent'], [], true),
            $d('quote.viewed', 'quote', 'Customer viewed the quotation.', ['QuoteViewed'], [], true),
            $d('quote.declined', 'quote', 'Quote declined or lost (WF-014).', ['QuoteDeclined'], [], true),
            $d('quote.expired', 'quote', 'Quote validity elapsed (QUOTE_EXPIRED).', ['QuoteExpired'], [], true),
            $d('quote.premium_override.requested', 'quote_offer', 'Premium override requested (maker-checker, BRK-035).', [], [], true),
            $d('quote.premium_override.applied', 'quote_offer', 'Approved premium override applied to the offer.', [], [], true),
            $d('quote.comparison.saved', 'quote', 'Quote comparison saved on normalized dimensions.', [], [], true),
            // Batch 6C — REQ-QUO-006 manual quotation (App\Application\CarrierOperations\QuoteRequests\QuoteRequestService)
            $d('carrier_quote_request.opened', 'carrier_quote_request', 'Quote sent to a MANUAL insurer\'s work queue (case + SLA).', [], [], true),
            $d('carrier_quote_request.offered', 'carrier_quote_request', 'Insurer offer recorded; quote offer created (origin MANUAL).', [], [], true),
            $d('carrier_quote_request.declined', 'carrier_quote_request', 'Insurer declined to quote.', [], [], true),
            $d('carrier_quote_request.cancelled', 'carrier_quote_request', 'Manual quotation request cancelled.', [], [], true),
            $d('carrier_quote_request.expired', 'carrier_quote_request', 'Manual quotation request closed because the quote expired.', [], [], true),
            $d('reconciliation.approved', 'reconciliation', 'Reconciliation approved.', [], [], true),
            // Batch 9-5 — REQ-PAY-007 reconciliation outcomes / exceptions queue / manual match
            $d('reconciliation.exception.raised', 'reconciliation_item', 'Statement line not MATCHED (PARTIAL/UNMATCHED/DUPLICATE/OVER/UNDER); RECONCILIATION_EXCEPTION case opened.', [], [], true),
            $d('reconciliation.refund_candidate.flagged', 'reconciliation_item', 'Duplicate payment flagged as refund candidate (DUPLICATE_PAYMENT, WF-026/027/086).', [], [], true),
            $d('reconciliation.manual_match.requested', 'reconciliation_item', 'Manual match of a statement line to a payment requested (maker).', [], [], true),
            $d('reconciliation.manual_match.approved', 'reconciliation_item', 'Manual match approved by an independent checker; line MATCHED.', [], [], true),
            $d('reconciliation.manual_match.rejected', 'reconciliation_item', 'Manual match rejected by the checker.', [], [], true),
            $d('renewal.quoted', 'policy', 'Renewal quote produced.', [], [], true),
            // REQ-REN-001 renewal machine (RenewalService, Renewals\RenewalIssuanceFailureLink)
            $d('renewal.window_reached', 'renewal_case', 'Renewal case reached a reminder window (90/60/30/15/7 days).', [], [], true),
            $d('renewal.lapsed', 'renewal_case', 'Renewal case lapsed: cover ended without a renewal quote.', [], [], true),
            $d('renewal.issuance_failed', 'renewal_case', 'Paid renewal could not be issued; queued as an issuance exception (WF-087).', [], [], true),
            $d('renewal.issuance_recovered', 'renewal_case', 'Paid-renewal issuance exception resolved (re-queued for issuance, or refunded).', [], [], true),
            $d('risk_asset.created', 'risk_asset', 'Risk asset created.', [], [], true),
            $d('risk_asset.updated', 'risk_asset', 'Risk asset updated.', [], [], true),
            $d('tariff.submitted', 'tariff', 'Tariff submitted for approval.', [], [], true),
            $d('tariff.scheduled', 'tariff', 'Tariff version scheduled for its effective date (REQ-RAT-002).', [], [], true),
            $d('tariff.expired', 'tariff', 'Tariff version expired (REQ-RAT-002).', [], [], true),

            // Batch 13D — REQ-COI-001 co-insurance (App\Application\Coinsurance\CoinsuranceService)
            $d('coinsurance.arrangement.created', 'coinsurance_arrangement', 'Co-insurance arrangement drafted.', [], [], true),
            $d('coinsurance.arrangement.activated', 'coinsurance_arrangement', 'Co-insurance arrangement activated (maker-checker).', [], [], true),
            $d('coinsurance.arrangement.terminated', 'coinsurance_arrangement', 'Co-insurance arrangement terminated.', [], [], true),
            $d('coinsurance.apportioned', 'coinsurance_arrangement', 'Amount apportioned across co-insurers.', [], [], true),
            // Batch 13C — REQ-REI-001/002 reinsurance (App\Application\Reinsurance)
            $d('reinsurance.treaty_version.activated', 'reinsurance_treaty', 'Treaty version activated (maker-checker).', [], [], true),
            $d('reinsurance.policy.ceded', 'policy', 'Policy cession calculated against treaties in force.', [], [], true),
            // Batch 13A — REQ-PRV-001/002/004 providers (App\Application\Providers)
            $d('provider.registered', 'provider', 'Provider registered in the provider master.', [], [], true),
            $d('provider.credentialing_changed', 'provider', 'Provider credentialing status changed.', [], [], true),
            $d('provider.relationship_added', 'provider', 'Provider relationship recorded.', [], [], true),
            $d('provider_network.created', 'provider_network', 'Provider network created.', [], [], true),
            $d('provider_network.member_added', 'provider_network', 'Provider added to a network.', [], [], true),
            $d('provider_contract.created', 'provider_contract', 'Provider contract created.', [], [], true),
            $d('provider_tariff.approved', 'provider_contract', 'Provider tariff version approved (maker-checker).', [], [], true),

            // Batch 8-6 — REQ-PRD-011 life & special products (App\Application\Policies\Special)
            $d('special_policy.profile.created', 'policy', 'Special product profile (group/fleet/open cover/construction/agriculture/life) attached to a policy.', [], [$P], true),
            $d('group_policy.member.added', 'policy', 'Member added to a group master policy schedule (dated, pro-rata premium).', [], [$P], true),
            $d('group_policy.member.removed', 'policy', 'Member removed from a group master policy schedule (dated, return premium).', [], [$P], true),
            $d('fleet_policy.vehicle.added', 'policy', 'Vehicle added to a fleet policy schedule.', [], [$P], true),
            $d('fleet_policy.vehicle.removed', 'policy', 'Vehicle removed from a fleet policy schedule.', [], [$P], true),
            $d('special_policy.schedule_item.added', 'policy', 'Construction/agriculture schedule entry added.', [], [$P], true),
            $d('special_policy.schedule_item.removed', 'policy', 'Construction/agriculture schedule entry removed.', [], [$P], true),
            $d('cargo_declaration.declared', 'policy', 'Shipment declared under a marine open cover.', [], [$P], true),
            $d('cargo_declaration.cancelled', 'policy', 'Cargo declaration cancelled.', [], [$P], true),
            $d('life_surrender_scale.activated', 'life_surrender_scale', 'Carrier surrender scale activated (maker-checker).', [], [$P], true),
            $d('life_surrender.quoted', 'policy', 'Life surrender value computed (rules-driven, carrier scale).', [], [$P], true),

            // Batch 10-1 — REQ-COM-001 commission machine (App\Application\Commissions\Machine\CommissionMachine)
            $d('commission.earned', 'commission', 'Commission earned: the premium it is based on is settled.', [], [$C], true),
            $d('commission.approved', 'commission', 'Commission approved by finance.', [], [$C], true),
            $d('commission.payable', 'commission', 'Commission payable: a PAYABLE COMMISSION obligation exists.', [], [$C], true),
            $d('commission.paid', 'commission', 'Commission fully paid to the intermediary.', [], [$C], true),
            $d('commission.adjusted', 'commission', 'Commission amount adjusted; awaits re-approval.', [], [$C], true),
            $d('commission.disputed', 'commission', 'Commission disputed.', [], [$C], true),
            $d('commission.dispute_resolved', 'commission', 'Commission dispute resolved; awaits re-approval.', [], [$C], true),
            $d('commission.reversed', 'commission', 'Commission reversed before it was earned or paid.', [], [$C], true),

            // --- Engine events ---
            $d('workflow.transition.applied', 'workflow', 'Generic state-machine transition applied (fallback when a transition names no domain event).', [], [$E]),
            $d('workflow.transition.rejected', 'workflow', 'State-machine transition rejected by guard/permission/authority.', [], [$E]),
        ];
    }

    private static function boot(): void
    {
        if (self::$byName !== null) {
            return;
        }
        self::$byName = [];
        self::$byAlias = [];
        foreach (self::definitions() as $e) {
            if (isset(self::$byName[$e->name])) {
                throw new InvalidArgumentException("Duplicate event {$e->name}.");
            }
            self::$byName[$e->name] = $e;
            foreach ($e->aliases as $a) {
                if (isset(self::$byAlias[$a])) {
                    throw new InvalidArgumentException("Alias {$a} mapped twice.");
                }
                self::$byAlias[$a] = $e->name;
            }
        }
    }

    /** @return array<string,EventDefinition> */
    public static function all(): array
    {
        self::boot();

        return self::$byName;
    }

    public static function has(string $name): bool
    {
        self::boot();

        return isset(self::$byName[$name]);
    }

    public static function get(string $nameOrAlias): EventDefinition
    {
        self::boot();
        $name = self::$byAlias[$nameOrAlias] ?? $nameOrAlias;

        return self::$byName[$name] ?? throw new InvalidArgumentException("Unknown domain event {$nameOrAlias}.");
    }

    /** Resolve WRS/PRE PascalCase alias (or a dotted name) to the canonical dotted name. */
    public static function canonicalName(string $nameOrAlias): string
    {
        return self::get($nameOrAlias)->name;
    }

    /** @return array<string,string> alias => canonical dotted name */
    public static function aliases(): array
    {
        self::boot();

        return self::$byAlias;
    }

    /** @return list<EventDefinition> */
    public static function bySource(string $source): array
    {
        return array_values(array_filter(self::all(), fn (EventDefinition $e) => in_array($source, $e->sources, true)));
    }
}
