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
            $d('policy.endorsement.issued', 'policy', 'Endorsement issued on a policy.', ['EndorsementIssued'], [$W]),
            $d('renewal.due', 'policy', 'Policy entered its renewal window.', ['RenewalDue'], [$W]),
            $d('renewal.completed', 'policy', 'Policy renewed.', ['PolicyRenewed'], [$W], true),
            $d('claim.fnol.submitted', 'claim', 'Claim reported (FNOL).', ['ClaimReported'], [$W], true),
            $d('claim.evidence.attached', 'claim', 'Claim evidence received.', ['ClaimEvidenceReceived'], [$W], true),
            $d('claim.assigned', 'claim', 'Claim assigned to a handler.', ['ClaimAssigned'], [$W], true),
            $d('claim.decision.approved', 'claim', 'Claim approved.', ['ClaimApproved'], [$W], true),
            $d('claim.decision.rejected', 'claim', 'Claim rejected/declined.', ['ClaimRejected'], [$W]),
            $d('claim.payment.paid', 'claim', 'Claim settlement paid.', ['ClaimSettled'], [$W], true),
            $d('refund.approved', 'refund', 'Refund approved.', ['RefundApproved'], [$W], true),
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
            $d('commission.calculated', 'commission', 'Commission calculated.', ['CommissionCalculated'], [$P]),

            // --- Already emitted by code, no spec alias ---
            $d('chargeback.resolved', 'chargeback', 'Payment chargeback resolved.', [], [], true),
            $d('claim.carrier_message.queued', 'claim', 'Message to carrier queued for a claim.', [], [], true),
            $d('claim.dispute.resolved', 'claim', 'Claim dispute resolved.', [], [], true),
            $d('claim.payment.approved', 'claim', 'Claim payment approved.', [], [], true),
            $d('claim.payment.reversed', 'claim', 'Claim payment reversed.', [], [], true),
            $d('claim.reserve.approved', 'claim', 'Claim reserve approved.', [], [], true),
            $d('claim.transitioned', 'claim', 'Generic claim status transition.', [], [], true),
            $d('commission.clawed_back', 'commission', 'Commission clawed back.', [], [], true),
            $d('commission.rule.approved', 'commission_rule', 'Commission rule approved.', [], [], true),
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
            $d('ledger.event.posted', 'ledger', 'Ledger journal posted.', [], [], true),
            $d('notification.delivery.requested', 'notification', 'Notification delivery requested.', [], [], true),
            $d('notification.delivery.sent', 'notification', 'Notification delivered to provider.', [], [], true),
            $d('partner.licence.decided', 'partner', 'Partner licence decision recorded.', [], [], true),
            $d('partner.licence.submitted', 'partner', 'Partner licence submitted.', [], [], true),
            $d('partner.payout.paid', 'partner_payout', 'Partner payout paid.', [], [], true),
            $d('partner.payout.reversed', 'partner_payout', 'Partner payout reversed.', [], [], true),
            $d('partner.statement.approved', 'partner_statement', 'Partner statement approved.', [], [], true),
            $d('party.created', 'party', 'Party created.', [], [], true),
            // REQ-PTY-002/003/004 golden record (App\Application\Customers\Roles|Relationships|Matching)
            $d('party.role_assigned', 'party', 'Explicit bitemporal party role recorded (LOCK-006).', [], [], true),
            $d('party.relationship_added', 'party', 'Party relationship recorded (household, employer, group, corporate).', [], [], true),
            $d('party.ownership_added', 'party', 'Ownership interest recorded (UBO graph).', [], [], true),
            $d('party.merged', 'party', 'Duplicate party merged into a survivor after maker-checker approval.', [], [], true),
            $d('party.unmerged', 'party', 'Applied party merge reversed.', [], [], true),
            $d('payment.authorization.requested', 'payment_intent', 'Payment authorization requested from payer.', [], [], true),
            $d('payment.status.changed', 'payment_intent', 'Payment status changed (carries successful/failed today).', [], [], true),
            $d('policy.issuance.rejected', 'policy', 'Policy issuance rejected.', [], [], true),
            $d('policy.issuance.requested', 'policy', 'Policy issuance requested.', [], [], true),
            $d('policy.service.approved', 'policy_service_request', 'Policy servicing request approved.', [], [], true),
            $d('policy.service.payment_requested', 'policy_service_request', 'Payment requested for a servicing change.', [], [], true),
            $d('policy.service.rejected', 'policy_service_request', 'Policy servicing request rejected.', [], [], true),
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
            $d('renewal.quoted', 'policy', 'Renewal quote produced.', [], [], true),
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
