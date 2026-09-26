<?php

declare(strict_types=1);

namespace App\Application\DocumentCatalogue;

/**
 * D2 (DOCUMENT_SECURITY_COMPLETION_PLAN): the 442 detailed-spec bullets of the 36 critical documents that
 * CanonicalFieldDictionary::BULLETS leaves UNMAPPED, mapped onto the platform table/column that holds the
 * data. Two outcomes per normalized bullet:
 *
 *  - [key, source]  MAPPED_PLATFORM_SOURCE: the platform holds the value in `source` (table.column, or a
 *                   described derivation). The key is a canonical field key; values render when present.
 *                   These are NOT enforced (a missing value does not block issuance): enforcement of new
 *                   keys is an owner decision, recorded in docs/spec/canonical/FIELD_RULE_COVERAGE.md.
 *  - [null, gap]    stays PENDING_VERIFICATION; `gap` names the data source the platform is missing.
 *
 * Only real columns are named: DetailedFieldSourceMap::references() is checked against the migrated schema by
 * MappedFieldSchemaTest. Source grammar (the one MappedFieldValues::read parses): alternatives split by " / ",
 * "table.col", "table.col1, col2", "table.fk -> table2.col", "sum(table.col)", a bare "table", "(notes)" ignored.
 * TEMPLATE_TEXT: a fixed wording that belongs to the published document template (document_templates.content has
 * no per-statement field), so no platform column is read. Nothing here invents a value.
 * Keys are looked up by the full normalized bullet, then by the bullet without its conditional wording.
 */
final class DetailedFieldSourceMap
{
    public const TEMPLATE_TEXT = 'TEMPLATE_TEXT';

    /** @var array<string, array{0: string|null, 1: string}> */
    public const MAP = [
        // ---------- DOC-001 quote ----------
        'assumptions' => ['quote.assumptions', 'quote_offers.calculation_breakdown / quotes.risk_facts'],
        'significant assumptions' => ['quote.assumptions', 'quote_offers.calculation_breakdown / quotes.risk_facts'],
        'base premium' => ['premium.base', 'quote_offers.premium_minor'],
        'net premium' => ['premium.net', 'quote_offers.premium_minor'],
        'discounts' => ['premium.discounts', 'quote_offers.calculation_breakdown (discount lines)'],
        'loadings' => ['premium.loadings', 'quote_offers.calculation_breakdown (loading lines)'],
        'installment option if offered' => ['premium.instalments', 'policy_premium_instalments / premium_cover_rules'],
        'optional/mandatory' => ['coverage.mandatory_flags', 'policy_coverages.mandatory, optional'],
        'material exclusions/conditions or references' => ['coverage.exclusions', 'product_exclusions.exclusion_definition_id -> exclusion_definitions.name'],
        'outstanding requirements' => ['underwriting.outstanding_requirements', 'proposals.information_request / underwriting_referral_tasks'],
        'premium contribution where exposed' => ['premium.gross', 'quote_offers.total_minor'],
        'referral/conditional status where relevant' => ['underwriting.referral_status', 'underwriting_decisions.outcome / quote_offers.sellability'],
        'that quotation does not itself prove active insurance unless legally/product-configured otherwise' => ['template.statement.quote_not_cover', 'TEMPLATE_TEXT'],
        'valid-until date' => ['quote.valid_until', 'quote_offers.valid_until'],
        'validity period' => ['document.validity', 'quote_offers.valid_until / health_preauthorizations.gop_valid_until'],
        'whether subject to underwriting' => ['underwriting.required', 'insurance_products.underwriting_mode'],

        // ---------- DOC-003 proposal ----------
        'applicant signature/acceptance' => ['proposal.attestation', 'proposals.attested_at / proposal_declarations.accepted_at'],
        'beneficiary information where applicable' => ['beneficiary.list', 'beneficiary_designations'],
        'claims/loss history where product requires it' => ['proposal.answers', 'proposal_disclosure_responses.answers'],
        'consent' => ['consent.record', 'consents / consent_events'],
        'coverage selections' => ['coverage.lines', 'quote_offers.coverage_snapshot'],
        'declaration of truth/completeness' => ['proposal.declarations', 'proposal_declarations.statement, accepted_at'],
        'disclosures' => ['proposal.answers', 'proposal_disclosure_responses.answers'],
        'payment preference' => [null, 'No stored payment preference on proposals (payment_intents only record the method used after checkout)'],
        'premium estimate' => ['premium.gross', 'quote_offers.total_minor'],
        'previous insurance/history where product requires it' => ['proposal.answers', 'proposal_disclosure_responses.answers'],
        'privacy/data-processing acknowledgements where applicable' => ['consent.record', 'consents.purpose, notice_version, given_at'],
        'proposal number' => ['proposal.number', 'proposals.proposal_number'],
        'proposed insured(s)' => ['party.insured', 'policy_parties (role INSURED) / proposals.party_id'],
        'proposed policyholder' => ['party.name', 'proposals.party_id -> parties.display_name'],
        'quote reference' => ['quote.number', 'quotes.quote_number'],
        'requested effective date' => ['proposal.requested_effective_date', 'proposals.cover_terms'],
        'required documents' => ['documents.required', 'product_document_requirements / document_requirement_matrix'],
        'risk information' => ['risk.summary', 'quote_risks.facts'],
        'submission timestamp' => ['proposal.submitted_at', 'proposals.submitted_at'],
        'underwriting-question answers' => ['proposal.answers', 'quote_answers.answers / proposal_disclosure_responses.answers'],

        // ---------- DOC-016 / DOC-017 schedule ----------
        'beneficiary roles where applicable' => ['beneficiary.list', 'beneficiary_designations.designation'],
        'certificates' => ['policy.certificates', 'policy_certificates.serial_number'],
        'clauses' => [null, 'No clause library with versioned clause texts (exclusion_legal_texts cover exclusions only)'],
        'endorsements' => ['policy.endorsements', 'policy_transactions (type ENDORSEMENT)'],
        'endorsements incorporated' => ['policy.endorsements', 'policy_transactions (type ENDORSEMENT, status APPLIED)'],
        'endorsement references' => ['policy.endorsements', 'policy_transactions.transaction_number'],
        'general conditions' => ['template.general_conditions', 'TEMPLATE_TEXT'],
        'notification/contact information' => ['issuer.contact', 'institution_profiles.phones, emails / institution_offices'],
        'policy schedule' => ['policy.schedule', 'policy_schedule_items / policy_coverages'],
        'schedules' => ['policy.schedule', 'policy_schedule_items'],

        // ---------- DOC-021 cover note ----------
        'conditions precedent' => ['underwriting.conditions', 'underwriting_decisions.conditions'],
        'coverage being temporarily evidenced' => ['coverage.lines', 'policy_coverages'],
        'expiration date/time' => ['policy.effective_until', 'policies.coverage_ends_at'],
        'explicit temporary/provisional nature' => ['template.statement.provisional', 'TEMPLATE_TEXT'],
        'inception date/time' => ['policy.effective_from', 'policies.coverage_starts_at'],
        'linked proposal/quote/policy' => ['proposal.number', 'proposals.proposal_number / quotes.quote_number / policies.policy_number'],
        'policyholder/insured' => ['party.name', 'policies.party_id -> parties.display_name'],
        'premium/payment status where required' => ['payment.status', 'payment_intents.status / policy_premium_instalments.status'],
        'risk' => ['risk.summary', 'policy_risks.display_name, facts'],
        'signature/approval' => ['approval.record', 'approval_requests / approval_decisions'],

        // ---------- DOC-036 motor attestation ----------
        'model year where required' => ['risk.model_year', 'risk_asset_vehicles.model_year'],
        'serial/security-stock information when applicable' => ['sticker.serial', 'sticker_stock.serial_number, batch_number'],

        // ---------- DOC-057 health declaration ----------
        'applicant/insured' => ['party.name', 'proposals.party_id -> parties.display_name'],
        'confidentiality classification' => ['confidentiality.class', 'document_types.confidentiality_class'],
        'consent/authorization' => ['consent.record', 'consents (purpose HEALTH_DATA)'],
        'current/past condition questions' => ['proposal.answers', 'proposal_disclosure_responses.answers (medical question set)'],
        'date completed' => ['proposal.attested_at', 'proposal_disclosure_responses.attested_at'],
        'date of birth' => ['party.date_of_birth', 'parties.legal_identity (privacy-gated)'],
        'declaration number' => ['document.number', 'documents.document_number'],
        'declarations' => ['proposal.declarations', 'proposal_declarations'],
        'disability questions where applicable' => ['proposal.answers', 'proposal_disclosure_responses.answers'],
        'hospitalization/surgery questions' => ['proposal.answers', 'proposal_disclosure_responses.answers'],
        'policy/proposal reference' => ['proposal.number', 'proposals.proposal_number / policies.policy_number'],
        'relevant medical questions' => ['proposal.answers', 'proposal_disclosure_responses.answers'],
        'tobacco/alcohol questions where permitted/relevant' => ['proposal.answers', 'proposal_disclosure_responses.answers'],
        'treatment/medication questions' => ['proposal.answers', 'proposal_disclosure_responses.answers'],

        // ---------- DOC-060 member card ----------
        'card status' => ['member.card_status', 'health_member_cards.status'],
        'dependant relationship where relevant' => ['member.relationship', 'health_members.relationship'],
        'emergency/provider contact' => [null, 'No emergency/assistance contact configured per product or network (provider_networks has no contact)'],
        'member name' => ['member.name', 'health_members.display_name'],
        'member number' => ['member.reference', 'health_members.member_number'],
        'network/plan' => ['member.network', 'health_policy_networks.provider_network_id -> provider_networks.name'],
        'optional copay/network indication' => ['benefit.copay', 'health_benefit_schedules.copay_bp'],
        'policy/group number' => ['policy.number', 'policies.policy_number'],
        'qr/member verification' => ['verification.token', 'health_member_cards.token_hash'],
        'validity dates' => ['member.validity', 'health_members.effective_from, effective_to'],

        // ---------- DOC-066 preauthorization decision ----------
        'approval timestamp' => ['preauth.decided_at', 'health_preauthorizations.decided_at'],
        'approved amount' => ['decision.approved_amount', 'health_preauthorizations.approved_amount_minor / claim_decisions.approved_amount_minor'],
        'approved quantity/days' => ['preauth.approved_quantity', 'health_preauthorization_lines.approved_quantity'],
        'approved service/procedure' => ['preauth.services', 'health_preauthorization_lines.service_code, line_decision'],
        'approving officer/engine' => ['preauth.decided_by', 'health_preauthorizations.decided_by'],
        'authorization number' => ['preauth.number', 'health_preauthorizations.preauth_number'],
        'authorization status' => ['preauth.status', 'health_preauthorizations.status, decision'],
        'conditions' => ['decision.conditions', 'health_preauthorizations.decision_notes / underwriting_decisions.conditions'],
        'exclusions/not-covered items' => ['preauth.declined_lines', 'health_preauthorization_lines.decline_reason'],
        'insurer responsibility' => ['preauth.insurer_amount', 'health_preauthorizations.insurer_amount_minor'],
        'member' => ['member.reference', 'health_preauthorizations.member_ref'],
        'member responsibility' => ['preauth.member_amount', 'health_preauthorization_lines.copay_minor'],
        'provider/facility' => ['provider.name', 'provider_profiles.official_name / provider_facilities'],
        'request reference' => ['preauth.number', 'health_preauthorizations.preauth_number'],
        'requested amount' => ['preauth.requested_amount', 'health_preauthorizations.requested_amount_minor'],
        'service code' => ['preauth.services', 'health_preauthorization_lines.service_code'],

        // ---------- DOC-069 guarantee of payment ----------
        'authorized signatory' => ['approval.signatory', 'signatory_authorities'],
        'authorized treatment/service' => ['preauth.services', 'health_preauthorization_lines'],
        'claim submission instructions' => [null, 'No provider claim-submission instructions stored on provider_contracts (document_reference only)'],
        'contact/escalation point' => [null, 'No escalation contact configured per carrier/network'],
        'effective time' => ['preauth.valid_from', 'health_preauthorizations.gop_valid_from'],
        'expiry time' => ['preauth.valid_until', 'health_preauthorizations.gop_valid_until'],
        'guarantee ceiling' => ['decision.approved_amount', 'health_preauthorizations.approved_amount_minor'],
        'guarantee number' => ['preauth.number', 'health_preauthorizations.preauth_number'],
        'insurer share' => ['preauth.insurer_amount', 'health_preauthorizations.insurer_amount_minor'],
        'member share' => ['preauth.member_amount', 'health_preauthorization_lines.copay_minor'],
        'non-guaranteed services' => ['preauth.declined_lines', 'health_preauthorization_lines (line_decision DECLINED)'],
        'provider' => ['provider.name', 'provider_profiles.official_name'],

        // ---------- DOC-076 life illustration ----------
        'adviser' => ['intermediary.name', 'policies.servicing_partner_id -> partners.legal_name'],
        'age/date of birth' => ['party.date_of_birth', 'parties.legal_identity (privacy-gated)'],
        'benefits' => ['coverage.lines', 'quote_offers.coverage_snapshot'],
        'charges' => [null, 'No life charge schedule (policy fees/charges) in the life product model'],
        'illustration date' => ['document.issued_at', 'documents.issued_at'],
        'maturity values' => [null, 'No life projection engine (maturity/projected values are not computed)'],
        'payment frequency' => ['premium.frequency', 'policy_premium_instalments (sequence/due_date)'],
        'projected values' => [null, 'No life projection engine (projected values are not computed)'],
        'proposed insured' => ['party.insured', 'policy_parties (role INSURED)'],
        'sum assured' => ['coverage.sum_insured', 'policy_coverages.limit_minor'],
        'surrender values' => ['life.surrender_value', 'life_surrender_quotes.net_value_minor'],
        'term' => ['policy.term', 'policies.coverage_starts_at, coverage_ends_at'],
        'validity/assumption basis' => [null, 'No verified life illustration basis (interest/mortality assumptions) configured'],
        'warnings' => ['template.statement.illustration_warning', 'TEMPLATE_TEXT'],

        // ---------- DOC-082 beneficiary designation ----------
        'allocation percentage' => ['beneficiary.allocation', 'beneficiary_designations.allocation_pct'],
        'beneficiary full name' => ['beneficiary.name', 'beneficiary_designations.full_name'],
        'beneficiary id/reference where permitted' => ['beneficiary.party', 'beneficiary_designations.party_id'],
        'beneficiary type' => ['beneficiary.designation', 'beneficiary_designations.designation'],
        'contact where permitted' => ['party.contact', 'party_contacts (primary)'],
        'contingent beneficiary where supported' => ['beneficiary.designation', 'beneficiary_designations.designation (CONTINGENT)'],
        'date of birth where needed' => ['beneficiary.date_of_birth', 'beneficiary_designations.date_of_birth'],
        'policy/proposal' => ['policy.number', 'policies.policy_number / proposals.proposal_number'],
        'policyholder declaration' => ['proposal.declarations', 'proposal_declarations'],
        'relationship' => ['beneficiary.relationship', 'beneficiary_designations.relationship'],
        'total allocation validation = 100% where applicable' => ['beneficiary.allocation_total', 'sum(beneficiary_designations.allocation_pct)'],
        'witness/notarization only if required/configured' => [null, 'No witness/notary capture on beneficiary designations'],

        // ---------- DOC-096 group health master ----------
        'benefit limits' => ['benefit.limits', 'health_benefit_schedules.period_limit_minor, per_event_limit_minor'],
        'claims rules' => ['product.claims_requirements', 'insurance_products.claims_requirements'],
        'contribution/premium basis' => [null, 'No group contribution basis (per member / payroll) stored on group schemes'],
        'corporate contacts' => ['party.contact', 'party_contacts.normalized_value (policyholder organisation)'],
        'covered member categories' => ['schedule.categories', 'policy_schedule_items.category'],
        'dependant rules' => ['product.eligibility_rules', 'insurance_products.eligibility_rules'],
        'eligibility rules' => ['product.eligibility_rules', 'insurance_products.eligibility_rules'],
        'eligible member definition' => ['product.eligibility_rules', 'insurance_products.eligibility_rules'],
        'enrollment rules' => [null, 'No group enrolment rules (joining windows, evidence of insurability) on special_policy_profiles.terms'],
        'group policy number' => ['policy.number', 'policies.policy_number'],
        'member certificate rules' => [null, 'No member certificate issuance rule configured for group schemes'],
        'sponsoring employer/organization' => ['party.name', 'policies.party_id -> parties.display_name'],
        'termination rules' => ['product.cancellation_rules', 'insurance_products.cancellation_rules / cancellation_rule_versions'],
        'waiting periods' => ['benefit.waiting_periods', 'health_benefit_schedules.waiting_period_days / health_benefit_rules.waiting_period_days'],

        // ---------- DOC-125 professional liability ----------
        'aggregate limit' => ['coverage.limits', 'policy_limits (limit_type AGGREGATE)'],
        'coverage type' => ['policy.insurance_class', 'insurance_products.product_class'],
        'insured professional/entity' => ['party.name', 'parties.display_name'],
        'limit per claim' => ['coverage.limits', 'policy_limits (limit_type PER_CLAIM)'],
        'material limitations/references' => ['coverage.exclusions', 'product_exclusions.exclusion_definition_id -> exclusion_definitions.name'],
        'profession/activity' => ['risk.summary', 'policy_risks.facts (activity)'],
        'territorial/jurisdictional scope' => [null, 'No territory/jurisdiction field on products or policies'],

        // ---------- DOC-133 marine cargo certificate ----------
        'beneficiary/loss payee if applicable' => ['policy.loss_payee', 'policy_parties.display_name, role (role LOSS_PAYEE)'],
        'bill of lading/airway bill/transport reference' => ['cargo.reference', 'cargo_declarations.reference'],
        'certificate period' => ['cargo.shipment_date', 'cargo_declarations.shipment_date'],
        'conveyance/vessel/vehicle' => ['cargo.conveyance', 'cargo_declarations.conveyance'],
        'coverage terms/clauses' => [null, 'No Institute Cargo Clause library (A/B/C) with verified texts'],
        'destination' => ['cargo.destination', 'cargo_declarations.destination'],
        'goods description' => ['cargo.goods', 'cargo_declarations.goods_description'],
        'insured value' => ['cargo.insured_value', 'cargo_declarations.insured_value_minor'],
        'invoice/value' => ['cargo.insured_value', 'cargo_declarations.insured_value_minor'],
        'open-policy/facility reference where applicable' => ['policy.number', 'special_policy_profiles.policy_id -> policies.policy_number'],
        'packing' => ['cargo.facts', 'cargo_declarations.facts (packing)'],
        'quantity' => ['cargo.facts', 'cargo_declarations.facts (quantity)'],
        'shipment date' => ['cargo.shipment_date', 'cargo_declarations.shipment_date'],
        'voyage origin' => ['cargo.origin', 'cargo_declarations.origin'],

        // ---------- DOC-138 travel certificate ----------
        'destination/territory' => ['risk.facts.destination', 'quote_risks.facts (destination)'],
        'emergency assistance' => [null, 'No travel assistance provider/contract recorded'],
        'emergency assistance number' => [null, 'No verified travel assistance phone number recorded'],
        'exclusions/reference' => ['coverage.exclusions', 'product_exclusions.exclusion_definition_id -> exclusion_definitions.name'],
        'insured traveler' => ['party.insured', 'policy_parties (role INSURED)'],
        'medical cover limit' => ['coverage.limits', 'policy_coverages.limit_minor (medical coverage)'],
        'other key benefits' => ['coverage.lines', 'policy_coverages'],
        'passport/id reference where privacy-permitted' => ['party.identifier_masked', 'party_identifiers.masked_value'],
        'repatriation cover' => ['coverage.lines', 'policy_coverages (repatriation coverage)'],
        'trip end' => ['policy.effective_until', 'policies.coverage_ends_at'],
        'trip start' => ['policy.effective_from', 'policies.coverage_starts_at'],

        // ---------- DOC-147 endorsement ----------
        'added/removed risk/coverage/party' => ['endorsement.changes', 'policy_transactions.requested_changes / policy_schedule_items'],
        'after value' => ['endorsement.terms_after', 'policy_transactions.terms_after'],
        'before value' => ['endorsement.terms_before', 'policy_transactions.terms_before'],
        'endorsement type' => ['endorsement.type', 'policy_transactions.endorsement_type'],
        'payment/refund requirement' => ['endorsement.financial_effect', 'policy_transactions.financial_effect, refund_minor'],
        'premium increase/reduction' => ['endorsement.premium_delta', 'policy_transactions.premium_delta_minor'],
        'revised policy period where applicable' => ['policy.period', 'policy_versions.valid_from, valid_to'],
        'tax/fee delta' => [null, 'policy_transactions carries premium_delta_minor only; no separate tax/fee delta'],
        'terms unaffected statement' => ['template.statement.terms_unaffected', 'TEMPLATE_TEXT'],

        // ---------- DOC-151 renewal notice ----------
        'current expiry date' => ['policy.effective_until', 'policies.coverage_ends_at'],
        'expiring product/plan' => ['policy.product', 'quote_offers.product_id -> insurance_products.name'],
        'material coverage changes' => ['renewal.changes', 'renewal_cases.renewal_quote_id -> quote_offers.coverage_snapshot (diff)'],
        'material exclusions/term changes' => ['renewal.changes', 'quote_offers.coverage_snapshot (renewal quote) vs policies.terms_snapshot'],
        'non-renewal/lapse consequence' => ['template.statement.lapse', 'TEMPLATE_TEXT'],
        'payment requirements' => ['payment.instructions', 'payment_provider_profiles / collection_accounts'],
        'proposed renewal period' => ['renewal.period', 'quotes.risk_facts (renewal quote period)'],
        'renewal acceptance instructions' => ['template.statement.renewal_acceptance', 'TEMPLATE_TEXT'],
        'renewal deadline' => ['renewal.due_on', 'renewal_cases.due_on'],
        'renewal premium' => ['renewal.premium', 'renewal_cases.renewal_quote_id -> quote_offers.total_minor'],
        'support contact' => ['issuer.contact', 'institution_profiles.phones, emails'],
        'taxes/fees' => ['premium.taxes', 'quote_offers.tax_minor, fee_minor'],

        // ---------- DOC-158 cancellation notice ----------
        'affected coverages' => ['coverage.lines', 'policy_coverages'],
        'appeal/contact information where applicable' => ['issuer.contact', 'institution_profiles.phones, emails'],
        'authority' => ['approval.decided_by', 'policy_cancellations.decided_by, authority_check_id'],
        'cancellation/termination reason code' => ['cancellation.reason', 'policy_cancellations.reason_code'],
        'claims implications where appropriate' => [null, 'No configured statement of claims implications per cancellation reason'],
        'effective termination date/time' => ['cancellation.effective_at', 'policy_cancellations.effective_at'],
        'notice date' => ['cancellation.notice_served_at', 'policy_cancellations.notice_served_at'],
        'notice number' => ['document.number', 'documents.document_number'],
        'premium status' => ['payment.status', 'policy_premium_instalments.status / financial_obligations.outstanding_minor'],
        'refund due or balance due' => ['cancellation.refund', 'policy_cancellations.refund_minor'],
        'reinstatement possibility where applicable' => [null, 'No reinstatement rule in cancellation_rule_versions'],

        // ---------- DOC-161 claim notification (FNOL) ----------
        'affected/injured parties' => ['claim.involved_parties', 'claim_involved_parties'],
        'catastrophe/event reference where relevant' => ['claim.catastrophe_event', 'claims.catastrophe_event_id -> catastrophe_events.code'],
        'cause' => ['claim.cause', 'claims.loss_details (cause) / claim_fnol_snapshots.reported_facts'],
        'claim number if already assigned' => ['claim.number', 'claims.claim_number'],
        'claimant signature/submission' => ['claim.submitted_at', 'claims.submitted_at / claim_fnol_snapshots.reporter_party_id'],
        'damage/injury description' => ['claim.loss_details', 'claims.loss_details'],
        'detailed narrative' => ['claim.loss_details', 'claim_fnol_snapshots.reported_facts'],
        'estimated loss if known' => ['claim.estimated_loss', 'claims.estimated_loss_minor'],
        'fraud warning' => ['template.statement.fraud_warning', 'TEMPLATE_TEXT'],
        'insured object' => ['risk.summary', 'claim_fnol_snapshots.policy_snapshot (risks)'],
        'invoices' => ['claim.evidence', 'claim_documents (evidence_type INVOICE)'],
        'location' => ['claim.loss_location', 'claims.loss_location'],
        'loss date/time' => ['claim.loss_date', 'claims.loss_occurred_at'],
        'medical records' => ['claim.evidence', 'claim_documents (evidence_type MEDICAL_REPORT)'],
        'notification date/time' => ['claim.reported_at', 'claim_fnol_snapshots.reported_at'],
        'other required evidence' => ['claim.evidence', 'claim_documents'],
        'photos' => ['claim.evidence', 'claim_documents (evidence_type PHOTO)'],
        'police report' => ['claim.evidence', 'claim_documents (evidence_type POLICE_REPORT)'],
        'police/reference number where relevant' => ['claim.facts', 'claim_fnol_snapshots.reported_facts (police_reference)'],
        'repair estimates' => ['claim.evidence', 'claim_documents (evidence_type REPAIR_ESTIMATE)'],
        'reporting channel' => ['claim.channel', 'claim_fnol_snapshots.channel'],
        'third parties' => ['claim.involved_parties', 'claim_involved_parties (role THIRD_PARTY)'],
        'truth declaration' => ['template.statement.truth_declaration', 'TEMPLATE_TEXT'],
        'videos' => ['claim.evidence', 'claim_documents (evidence_type VIDEO)'],
        'witnesses' => ['claim.involved_parties', 'claim_involved_parties (role WITNESS)'],

        // ---------- DOC-162 claim acknowledgement ----------
        'assigned claims contact/team' => ['claim.assigned_to', 'claims.assigned_to / claim_assignments'],
        'contact details' => ['issuer.contact', 'institution_profiles.phones, emails'],
        'current claim status' => ['claim.status', 'claims.status'],
        'next steps' => [null, 'No configured next-steps text per claim status'],
        'reported date' => ['claim.reported_at', 'claims.submitted_at'],
        'sla/expected response wording only when configured' => ['sla.due_at', 'sla_clocks.due_at, deadline_label (claim acknowledgement SLA)'],
        'statement that acknowledgement is not an admission of liability/coverage where applicable' => ['template.statement.no_admission', 'TEMPLATE_TEXT'],

        // ---------- DOC-169 assessment report ----------
        'affected risk' => ['risk.summary', 'claim_fnol_snapshots.policy_snapshot (risks)'],
        'appointment reference' => ['claim.assessment_number', 'claim_assessments.assessment_number'],
        'assessor declaration' => [null, 'No assessor declaration/independence statement captured on claim_assessments'],
        'assessor/adjuster' => ['claim.assessor', 'claim_assessments.assessor_user_id'],
        'claim' => ['claim.number', 'claims.claim_number'],
        'conflicts/limitations' => [null, 'No conflict-of-interest / limitations capture on claim_assessments'],
        'coverage observations' => ['claim.coverage_check', 'claim_coverage_checks.outcome, reasons'],
        'depreciation where applicable' => ['settlement.breakdown', 'claim_assessments.heads (depreciation head)'],
        'estimated loss' => ['claim.estimated_loss', 'claims.estimated_loss_minor'],
        'evidence reviewed' => ['claim.evidence', 'claim_documents (verified_at not null)'],
        'inspection date' => [null, 'No inspection date on claim_assessments'],
        'observed damage' => ['claim.assessment_rationale', 'claim_assessments.rationale, heads'],
        'photographs/attachments' => ['claim.evidence', 'claim_documents / claim_assessments.adjuster_report_document_id'],
        'pre-loss value where applicable' => ['risk.value', 'risk_asset_vehicles.market_value, declared_value'],
        'recommended settlement' => ['claim.recommended_total', 'claim_assessments.recommended_total_minor'],
        'repair/replacement assessment' => ['claim.assessment_heads', 'claim_assessments.heads'],
        'reservations' => ['claim.assessment_review_note', 'claim_assessments.review_note'],
        'salvage' => ['claim.recoveries', 'claim_recoveries (type SALVAGE)'],
        'signature/date' => ['approval.reviewed_at', 'claim_assessments.reviewed_by, reviewed_at'],

        // ---------- DOC-174 claim decision ----------
        'appeal/review mechanism where applicable' => ['claim.appeal', 'claim_disputes / claim_decisions.appeal_of_decision_id'],
        'approving officer' => ['approval.decided_by', 'claim_decisions.approved_by'],
        'coverage assessed' => ['claim.coverage_check', 'claim_coverage_checks.coverage_code, outcome'],
        'decision reference' => ['claim.decision_id', 'claim_decisions.id'],
        'decision timestamp' => ['claim.decided_at', 'claim_decisions.approved_at'],
        'decision: approved / partially approved / rejected' => ['claim.decision', 'claim_decisions.decision'],
        'delegated authority reference' => ['approval.authority', 'claim_decisions.authority_check_id, authority_snapshot'],
        'explanation' => ['claim.decision_rationale', 'claim_decisions.rationale'],
        'gross assessed loss' => ['settlement.gross', 'claim_settlements.gross_minor'],
        'insured/claimant' => ['party.claimant', 'claims.claimant_party_id -> parties.display_name'],
        'limits/sublimits' => ['coverage.limits', 'policy_limits'],
        'policy clause/coverage references' => ['claim.coverage_check', 'claim_coverage_checks.coverage_code'],
        'prior payments' => ['settlement.prior_payments', 'claim_settlements.prior_payments_minor'],
        'reason codes' => ['claim.reason_codes', 'claim_decisions.reason_codes, reason_code'],
        'recoveries/offsets where applicable' => ['claim.recoveries', 'claim_recoveries.recovered_amount_minor'],
        'rejected amount' => ['settlement.excluded', 'claim_settlements.excluded_minor'],

        // ---------- DOC-177 settlement offer ----------
        'acceptance instructions' => ['template.statement.offer_acceptance', 'TEMPLATE_TEXT'],
        'banking/payment requirements' => ['payee.bank', 'claim_involved_parties.bank_account_masked'],
        'calculation' => ['settlement.breakdown', 'claim_settlements.breakdown'],
        'claimant/payee' => ['settlement.payee', 'claim_settlements.payee_party_id -> parties.display_name'],
        'insurer contact' => ['issuer.contact', 'institution_profiles.phones, emails'],
        'offer amount' => ['settlement.amount', 'claim_settlements.amount_minor'],
        'prior/interim payments' => ['settlement.prior_payments', 'claim_settlements.prior_payments_minor'],
        'release/discharge requirements' => ['settlement.discharge', 'claim_settlements.discharge_signed_at / claim_settlements.discharge_document_id -> documents.document_number'],
        'settlement reference' => ['settlement.reference', 'claim_settlements.reference'],
        'tax/withholding where applicable' => [null, 'No verified withholding-tax rule for claim settlements (tax_levy_versions covers premium only)'],
        'validity period of offer' => [null, 'No offer validity/expiry on claim_settlements (offered_at only)'],

        // ---------- DOC-179 discharge ----------
        'agreed amount' => ['settlement.amount', 'claim_settlements.amount_minor'],
        'date' => ['settlement.discharge_signed_at', 'claim_settlements.discharge_signed_at'],
        'declaration' => ['template.statement.discharge', 'TEMPLATE_TEXT'],
        'nature of settlement' => ['settlement.nature', 'claim_decisions.kind / claim_settlements.breakdown'],
        'outstanding/reserved matters if partial' => ['claim.reserve_outstanding', 'claims.current_reserve_minor'],
        'payment details/reference where appropriate' => ['claim_payment.reference', 'claim_payments.external_reference'],
        'scope of discharge/release' => [null, 'No release-scope wording configured per settlement nature (legal text PENDING_VERIFICATION)'],
        'witness/approval if required' => ['approval.decided_by', 'claim_decisions.approved_by'],

        // ---------- DOC-181 claim payment advice ----------
        'assessed amount' => ['settlement.gross', 'claim_settlements.gross_minor'],
        'depreciation' => ['settlement.breakdown', 'claim_settlements.breakdown (depreciation)'],
        'limit application' => ['settlement.limit', 'claim_settlements.remaining_limit_minor, limit_capped'],
        'net payable' => ['settlement.amount', 'claim_settlements.amount_minor'],
        'payee' => ['settlement.payee', 'claim_payments.payee_party_id -> parties.display_name'],
        'payment date/status' => ['claim_payment.status', 'claim_payments.paid_at, status'],
        'payment method/reference' => ['claim_payment.reference', 'claim_payments.external_reference'],
        'prior payment' => ['settlement.prior_payments', 'claim_settlements.prior_payments_minor'],
        'recovery/offset' => ['settlement.adjustments', 'claim_settlements.adjustments_minor'],
        'remaining reserve or claim status' => ['claim.reserve_outstanding', 'claims.current_reserve_minor, status'],
        'salvage deduction' => ['settlement.breakdown', 'claim_settlements.breakdown (salvage)'],

        // ---------- DOC-186 premium invoice ----------
        'billed party' => ['party.name', 'financial_obligations.debtor_id -> parties.display_name'],
        'billing period' => ['policy.period', 'policies.coverage_starts_at, coverage_ends_at'],
        'amount previously paid' => ['obligation.paid', 'policy_premium_instalments.paid_minor / financial_obligations.amount_minor, outstanding_minor'],
        'branch/contact' => ['branch.name', 'tenant_branches.name, phone_e164, email'],
        'due date' => ['obligation.due_at', 'financial_obligations.due_at / policy_premium_instalments.due_date'],
        'fees' => ['premium.fees', 'quote_offers.fee_minor / premium_components (FEE)'],
        'insurer/payee' => ['policy.insurer', 'policies.carrier_id -> carriers.legal_name'],
        'levies' => ['premium.taxes', 'premium_components (LEVY)'],
        'payment instructions' => ['payment.instructions', 'collection_accounts / payment_provider_profiles'],
        'payment references' => ['payment.reference', 'payment_intents.provider_reference'],
        'premium components' => ['premium.components', 'premium_components.component, amount_minor'],
        'taxes' => ['premium.taxes', 'quote_offers.tax_minor / premium_components (TAX)'],
        'total' => ['obligation.amount', 'financial_obligations.amount_minor'],

        // ---------- DOC-190 receipt ----------
        'amount in words where configured' => ['payment.amount_words', 'payment_intents.amount_minor (AmountInWords, FR/EN)'],
        'reversal/refund status where applicable' => ['refund.status', 'refunds.status'],

        // ---------- DOC-194 broker/agent statement ----------
        'adjustments' => ['statement.adjustments', 'partner_statements.adjustments_minor'],
        'cancellations/refunds' => ['statement.items', 'partner_statement_items (entry_type CLAWBACK) / refunds'],
        'closing balance' => ['statement.closing_balance', 'partner_statements.closing_balance_minor'],
        'collected premium' => ['statement.collected_premium', 'premium_remittances.amount_minor / cashier_collections'],
        'commissions' => ['statement.commission', 'partner_statements.earned_minor / commission_accruals'],
        'opening balance' => ['statement.opening_balance', 'partner_statements.opening_balance_minor'],
        'outstanding premium' => ['obligation.outstanding', 'financial_obligations.outstanding_minor'],
        'policies/transactions' => ['statement.items', 'partner_statement_items'],
        'preparation/approval' => ['approval.record', 'partner_statements.prepared_by, approved_by, approved_at'],
        'remittances' => ['statement.remittances', 'premium_remittances'],
        'reporting period' => ['statement.period', 'partner_statements.period_start, period_end / bordereaux.period_start, period_end / regulatory_report_runs.period_key, period_from, period_to'],
        'settlement due' => ['statement.closing_balance', 'partner_statements.closing_balance_minor'],
        'written premium' => ['statement.written_premium', 'bordereaux.gross_premium_minor'],

        // ---------- DOC-197 insurer settlement statement ----------
        'amount due insurer' => ['settlement_batch.net', 'settlement_batches.net_amount_minor'],
        'amount paid' => ['settlement_batch.paid', 'settlement_batches.paid_at, bank_reference'],
        'approval' => ['approval.record', 'settlement_batches.approved_by, approved_at'],
        'commissions retained/payable' => ['settlement_batch.commission', 'bordereaux.commission_minor'],
        'gross premium collected' => ['settlement_batch.gross', 'bordereaux.gross_premium_minor'],
        'policies/receipts included' => ['settlement_batch.items', 'settlement_items / bordereau_items'],
        'prior balance' => [null, 'No carried-forward balance on settlement_batches'],
        'reconciliation exceptions' => ['reconciliation.exceptions', 'reconciliation_items (status EXCEPTION)'],
        'refunds' => ['refund.amount', 'refunds.amount_minor'],
        'settlement number' => ['settlement_batch.number', 'settlement_batches.settlement_number'],
        'settlement status' => ['settlement_batch.status', 'settlement_batches.status'],

        // ---------- DOC-201 reinsurance slip ----------
        'amount ceded' => ['reinsurance.ceded_premium', 'reinsurance_cessions.ceded_premium_minor / facultative_placements.placed_share_percent, premium_minor'],
        'brokerage' => ['reinsurance.brokerage', 'facultative_placements.brokerage_percent'],
        'cedant' => ['reinsurance.cedant', 'reinsurance_treaties.cedant_party_id / facultative_placements.tenant_id'],
        'claims cooperation terms' => ['reinsurance.claims_cooperation', 'reinsurance_treaty_versions.claims_cooperation_threshold_minor'],
        'commission' => ['reinsurance.commission', 'facultative_placements.commission_percent'],
        'governing reference' => [null, 'No governing law/jurisdiction on facultative placements or treaties'],
        'insured/risk' => ['risk.summary', 'facultative_placements.risk_description'],
        'original policy/product' => ['policy.number', 'facultative_placements.policy_id -> policies.policy_number'],
        'original premium' => ['reinsurance.original_premium', 'facultative_placements.premium_minor'],
        'participation' => ['reinsurance.participation', 'facultative_participants.signed_percent'],
        'reinsurance broker where applicable' => ['reinsurance.broker', 'facultative_placements.broker_id -> partners.legal_name'],
        'reinsurer/participants' => ['reinsurer.name', 'facultative_participants.reinsurer_id -> reinsurers.name'],
        'retention' => ['reinsurance.retention', 'reinsurance_treaty_versions.retention_minor'],
        'signature/acceptance status' => ['reinsurance.status', 'facultative_placements.status, decided_at'],
        'slip number' => ['reinsurance.reference', 'facultative_placements.reference'],
        'sum insured/exposure' => ['reinsurance.sum_insured', 'facultative_placements.sum_insured_minor'],
        'terms' => ['reinsurance.terms', 'facultative_placements.terms'],
        'type of reinsurance' => ['reinsurance.type', 'reinsurance_treaties.reinsurance_type (facultative when facultative_placements)'],

        // ---------- DOC-206 / DOC-208 bordereaux ----------
        'bordereau number' => ['bordereau.number', 'bordereaux.bordereau_number'],
        'ceded amount' => ['reinsurance.ceded_premium', 'reinsurance_cessions.ceded_premium_minor'],
        'gross sum insured' => ['reinsurance.sum_insured', 'reinsurance_cessions.sum_insured_minor'],
        'inception/expiry' => ['policy.period', 'policies.coverage_starts_at, coverage_ends_at'],
        'reinsurer' => ['reinsurer.name', 'reinsurance_cession_shares.reinsurer_id -> reinsurers.name'],
        'reinsurer share' => ['reinsurance.share', 'reinsurance_cession_shares.share_percent'],
        'risk identifier' => ['risk.identifier', 'policy_risks.risk_asset_id / policies.policy_number'],
        'treaty' => ['treaty.reference', 'reinsurance_treaties.treaty_number, code'],
        'catastrophe/event identifier if relevant' => ['claim.catastrophe_event', 'claims.catastrophe_event_id -> catastrophe_events.code'],
        'claim status' => ['claim.status', 'claims.status'],
        'date of loss' => ['claim.loss_date', 'claims.loss_occurred_at'],
        'gross incurred' => ['reinsurance.gross_incurred', 'reinsurance_recoveries.gross_incurred_minor'],
        'insurer retention' => ['reinsurance.retention', 'reinsurance_treaty_versions.retention_minor'],
        'outstanding reserve' => ['claim.reserve_outstanding', 'claims.current_reserve_minor'],
        'paid' => ['reinsurance.gross_paid', 'reinsurance_recoveries.gross_paid_minor'],
        'recoverable' => ['reinsurance.recoverable', 'reinsurance_recoveries.recoverable_minor'],
        'recovery received' => ['reinsurance.settled', 'reinsurance_recoveries.settled_minor'],
        'report date' => ['document.issued_at', 'documents.issued_at'],

        // ---------- DOC-215 provider agreement ----------
        'annexes' => ['provider_contract.document', 'provider_contracts.document_reference'],
        'audit rights' => [null, 'No provider contract clause store (audit rights wording)'],
        'billing rules' => [null, 'No provider billing rules on provider_contracts'],
        'claim submission rules' => [null, 'No provider claim-submission rules on provider_contracts'],
        'confidentiality/data protection' => [null, 'No provider contract clause store (data-protection wording)'],
        'contract number' => ['provider_contract.number', 'provider_contracts.contract_number'],
        'credentialing requirements' => ['provider.credentialing', 'provider_profiles.credentialing_status / provider_credentialing_events'],
        'dispute process' => [null, 'No provider dispute clause on provider_contracts (provider_disputes records disputes, not terms)'],
        'effective dates' => ['provider_contract.period', 'provider_contracts.effective_from, effective_to'],
        'eligibility process' => [null, 'No eligibility-check procedure text on provider contracts'],
        'facilities covered' => ['provider.facilities', 'provider_facilities (provider_profile_id)'],
        'fraud/abuse obligations' => [null, 'No provider contract clause store (fraud/abuse obligations)'],
        'network' => ['provider.network', 'provider_contracts.provider_network_id -> provider_networks.name'],
        'preauthorization rules' => [null, 'No preauthorization rule set on provider_contracts'],
        'provider legal entity' => ['provider.name', 'provider_profiles.official_name, registration_number'],
        'renewal/termination' => [null, 'No renewal/termination terms on provider_contracts'],
        'service scope' => ['provider.services', 'provider_tariff_lines.medical_service_id -> medical_services.name / provider_facility_services.specialty_code'],
        'supporting documents' => ['provider_contract.document', 'provider_contracts.document_reference'],
        'tariff schedule reference' => ['provider.tariff', 'provider_tariff_versions.version, source_document_reference'],

        // ---------- DOC-219 regulatory return ----------
        'amendment/version status' => ['regulatory.run_status', 'regulatory_report_runs.status, reversed_at'],
        'approver' => ['approval.decided_by', 'regulatory_report_runs.approved_by'],
        'certification/declaration' => [null, 'No verified CIMA certification wording for returns'],
        'preparer' => ['regulatory.prepared_by', 'regulatory_report_runs.prepared_by'],
        'regulator' => ['regulatory.authority', 'regulatory_report_definitions.jurisdiction (regulatory_authorities by regime)'],
        'reporting currency' => ['regulatory.currency', 'regulatory_report_runs.payload (currency)'],
        'reporting entity' => ['issuer.legal_name', 'regulatory_report_runs.tenant_id -> tenants.legal_name'],
        'required regulatory line items' => [null, 'CIMA return line items are PENDING_OFFICIAL_IMPORT (regulatory_report_dictionary_lines not verified)'],
        'return type' => ['regulatory.report_type', 'regulatory_report_definitions.report_type, code'],
        'reviewer' => [null, 'regulatory_report_runs has preparer and approver only; no separate reviewer'],
        'source-system lineage' => ['regulatory.lineage', 'regulatory_report_run_lineage.source_table, source_id'],
        'submission period' => ['regulatory.period', 'regulatory_report_runs.period_key, period_from, period_to'],
        'submission reference' => ['regulatory.external_reference', 'regulatory_report_runs.external_reference'],
        'totals' => ['regulatory.totals', 'regulatory_report_runs.payload (totals), row_count'],
    ];

    /**
     * @return array{status: string, key: string|null, source: string}|null  null when the bullet is not in the map
     */
    public static function lookup(string $bullet): ?array
    {
        $n = CanonicalFieldDictionary::normalize($bullet);
        $base = trim((string) preg_replace(CanonicalFieldDictionary::CONDITIONAL, '', $n), " \t;.,:");
        $hit = self::MAP[$n] ?? self::MAP[$base] ?? null;
        if ($hit === null) {
            return null;
        }

        return $hit[0] === null
            ? ['status' => 'UNMAPPED_PENDING_VERIFICATION', 'key' => null, 'source' => $hit[1]]
            : ['status' => 'MAPPED_PLATFORM_SOURCE', 'key' => $hit[0], 'source' => $hit[1]];
    }
    /**
     * Every table and column a mapped source names, parsed with the MappedFieldValues::read grammar.
     *
     * @return array<int, array{bullet: string, table: string, column: string|null}>
     */
    public static function references(): array
    {
        $out = [];
        foreach (self::MAP as $bullet => [$key, $source]) {
            if ($key === null || $source === self::TEMPLATE_TEXT) {
                continue;
            }
            foreach (preg_split('#\s+/\s+#', $source) as $alt) {
                $alt = preg_match('/^\s*sum\(([\w.]+)\)/', $alt, $m) ? $m[1] : trim((string) preg_replace('/\s*\(.*$/', '', $alt));
                if (preg_match('/^(\w+)\.(\w+)\s*->\s*(\w+)\.(\w+)$/', $alt, $m)) {
                    $out[] = ['bullet' => $bullet, 'table' => $m[1], 'column' => $m[2]];
                    $out[] = ['bullet' => $bullet, 'table' => $m[3], 'column' => $m[4]];
                } elseif (preg_match('/^(\w+)\.(\w+(?:\s*,\s*\w+)*)$/', $alt, $m)) {
                    foreach (array_map('trim', explode(',', $m[2])) as $c) {
                        $out[] = ['bullet' => $bullet, 'table' => $m[1], 'column' => $c];
                    }
                } elseif (preg_match('/^(\w+)$/', $alt, $m)) {
                    $out[] = ['bullet' => $bullet, 'table' => $m[1], 'column' => null];
                } else {
                    $out[] = ['bullet' => $bullet, 'table' => '?unparseable: '.$alt, 'column' => null];
                }
            }
        }

        return $out;
    }
}
