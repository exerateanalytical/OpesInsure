<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace;

/**
 * Provider Portal Hospital/Clinic Gap-Free spec v1 — single register of how every spec element is served by the
 * platform (canonical merge key provider_portal, experience layer #7 "Provider Portal"). Used by the screens API, the
 * Filament provider panel navigation and the completeness tests: a spec screen / entity / API / report that is not
 * mapped here fails the tests.
 */
final class ProviderWorkspaceRegister
{
    public const PORTAL_CODE = 'PROVIDER_PORTAL';

    public const UI_STATES = ['LOADING', 'EMPTY', 'SUCCESS', 'ERROR', 'OFFLINE', 'PERMISSION_DENIED', 'INSURER_UNAVAILABLE', 'VALIDATION_FAILED', 'MANUAL_REVIEW_REQUIRED'];

    /** Spec screen => [panel page slug, API path (under /api/v1/provider-portal), permission]. Every screen has EN/FR keys provider_workspace.screens.<slug>. */
    public const SCREENS = [
        'Provider Login' => ['login', null, null],
        'Provider Dashboard' => ['dashboard', 'dashboard', 'provider.dashboard.view'],
        'Facility Selector' => ['facilities', 'facilities', 'provider_portal.profile.view'],
        'Patient Search' => ['eligibility', 'eligibility/check', 'provider.patient.search'],
        'Eligibility Check' => ['eligibility', 'eligibility/check', 'provider.eligibility.check'],
        'Eligibility Result' => ['eligibility', 'eligibility/{verification_id}', 'provider.eligibility.check'],
        'Benefit Details' => ['eligibility', 'eligibility/{verification_id}', 'provider.benefits.view'],
        'Preauthorization List' => ['preauthorizations', 'preauthorizations', 'provider.preauth.view'],
        'New Preauthorization' => ['preauthorizations', 'preauthorizations', 'provider.preauth.create'],
        'Preauthorization Detail' => ['preauthorizations', 'preauthorizations/{id}', 'provider.preauth.view'],
        'Preauthorization Query Response' => ['preauthorizations', 'preauthorizations/{id}/respond-to-query', 'provider.preauth.respond_to_query'],
        'Admissions List' => ['admissions', 'admissions', 'provider.preauth.view'],
        'New Admission' => ['admissions', 'admissions', 'provider.admission.create'],
        'Admission Detail' => ['admissions', 'admissions/{id}', 'provider.preauth.view'],
        'Extension Request' => ['admissions', 'admissions/{id}/extensions', 'provider.admission.extend'],
        'Discharge' => ['admissions', 'admissions/{id}/discharge', 'provider.admission.create'],
        'Treatment Episodes' => ['treatment-episodes', 'treatment-episodes', 'provider.treatment.view'],
        'Provider Claims Dashboard' => ['dashboard', 'dashboard', 'provider.claim.view'],
        'Claims List' => ['claims', 'claims', 'provider.claim.view'],
        'New Provider Claim' => ['claims', 'claims', 'provider.claim.create'],
        'Claim Detail' => ['claims', 'claims/{id}', 'provider.claim.view'],
        'Claim Query Response' => ['claims', 'claims/{id}/respond-to-query', 'provider.claim.respond_to_query'],
        'Invoices' => ['claims', 'claims/search', 'provider.claim.view'],
        'Invoice Detail' => ['claims', 'claims/{id}', 'provider.claim.view'],
        'Provider Accounts' => ['accounts', 'accounts', 'provider.finance.view'],
        'Insurer Account Detail' => ['accounts', 'accounts/{insurer_id}', 'provider.finance.view'],
        'Settlements' => ['settlements', 'settlements', 'provider.settlement.view'],
        'Settlement Detail' => ['settlements', 'settlements/{id}', 'provider.settlement.view'],
        'Reconciliation' => ['reconciliations', 'reconciliations', 'provider.reconciliation.view'],
        'Reconciliation Detail' => ['reconciliations', 'reconciliations/{id}', 'provider.reconciliation.view'],
        'Disputes' => ['disputes', 'disputes', 'provider.dispute.view'],
        'Dispute Detail' => ['disputes', 'disputes/{id}', 'provider.dispute.view'],
        'Contracts' => ['contracts', 'contracts', 'provider_portal.network.view'],
        'Contract Detail' => ['contracts', 'contracts/{id}', 'provider_portal.network.view'],
        'Tariff Schedules' => ['contracts', 'tariffs', 'provider_portal.tariffs.view'],
        'Tariff Detail' => ['contracts', 'tariffs/{id}', 'provider_portal.tariffs.view'],
        'Documents' => ['documents', 'documents', 'provider.documents.view'],
        'Reports' => ['reports', 'reports/{report}', 'provider.reports.view'],
        'Notifications' => ['notifications', 'notifications', 'provider.dashboard.view'],
        'Provider Profile' => ['profile', 'profile', 'provider_portal.profile.view'],
        'Facility Management' => ['facilities', 'facilities/{id}/departments', 'provider.settings.manage'],
        'User Management' => ['users', 'users', 'provider.users.manage'],
        'Roles & Permissions' => ['users', 'roles', 'provider.users.manage'],
        'Integration Settings' => ['integration', 'integration', 'provider.settings.manage'],
        'Audit Log' => ['audit', 'audit', 'provider.audit.view'],
    ];

    /** Spec database entity => canonical table (no parallel tables). */
    public const ENTITY_MAP = [
        'providers' => 'provider_profiles', 'provider_facilities' => 'provider_facilities', 'provider_departments' => 'provider_departments',
        'provider_users' => 'provider_users', 'provider_roles' => 'master_data_values(provider.provider_user_role)',
        'provider_network_memberships' => 'provider_network_memberships', 'provider_insurer_contracts' => 'provider_contracts',
        'provider_tariff_schedules' => 'provider_tariff_versions', 'provider_tariff_lines' => 'provider_tariff_lines',
        'eligibility_checks' => 'health_eligibility_checks', 'eligibility_results' => 'health_eligibility_checks.reasons',
        'preauthorizations' => 'health_preauthorizations', 'preauthorization_lines' => 'health_preauthorization_lines',
        'preauthorization_decisions' => 'health_preauthorization_events', 'admissions' => 'health_preauthorizations(request_type=ADMISSION)',
        'admission_extensions' => 'health_preauthorization_extensions', 'treatment_episodes' => 'treatment_episodes',
        'provider_claims' => 'health_provider_claims', 'provider_claim_lines' => 'health_provider_claim_lines',
        'provider_claim_adjudications' => 'health_provider_claim_lines(decision,reason_code) + health_provider_claim_events',
        'provider_invoices' => 'health_provider_claims(invoice_reference)', 'provider_invoice_lines' => 'health_provider_claim_lines',
        'provider_accounts' => 'derived: ProviderWorkspaceService::accounts (never an editable balance)',
        'provider_account_entries' => 'derived: ProviderWorkspaceService::entries', 'provider_settlements' => 'health_provider_settlement_batches',
        'provider_settlement_lines' => 'health_provider_claims(settlement_batch_id)', 'provider_reconciliations' => 'provider_reconciliations',
        'provider_reconciliation_lines' => 'provider_reconciliation_lines', 'provider_disputes' => 'provider_disputes',
        'provider_documents' => 'documents (DocumentEngine; GOP pack via health_preauthorizations.gop_manifest_id)', 'provider_audit_links' => 'audit_log',
    ];

    public const REPORTS = ['PROVIDER_RECEIVABLES_BY_INSURER', 'PROVIDER_RECEIVABLE_AGING', 'CLAIMS_BY_STATUS', 'CLAIMS_BY_INSURER', 'CLAIMS_BY_FACILITY', 'CLAIMS_BY_SERVICE',
        'CLAIMS_BY_PATIENT', 'PREAUTH_TURNAROUND', 'PREAUTH_APPROVAL_RATE', 'CLAIM_APPROVAL_RATE', 'CLAIM_REJECTION_RATE', 'DEDUCTION_ANALYSIS', 'DISPUTE_ANALYSIS',
        'SETTLEMENT_PERFORMANCE', 'UNRECONCILED_PAYMENTS', 'TARIFF_VARIANCE', 'PATIENT_SHARE_REGISTER', 'PROVIDER_ACTIVITY_BY_INSURER', 'BULK_PAYMENT_ALLOCATION_REPORT'];

    public const FILTERS = ['date_from', 'date_to', 'insurer_id', 'provider_id', 'facility_id', 'patient_id', 'member_id', 'policy_id', 'employer_or_group_id', 'department_id',
        'doctor_id', 'service_code', 'service_family', 'diagnosis_category', 'preauth_status', 'claim_status', 'invoice_status', 'settlement_status', 'reconciliation_status',
        'aging_bucket', 'amount_min', 'amount_max', 'payment_status', 'dispute_status'];

    public const AGING_BUCKETS = ['CURRENT', 'DAYS_1_30', 'DAYS_31_60', 'DAYS_61_90', 'DAYS_91_120', 'DAYS_120_PLUS'];

    /** Adjudication reason (ProviderClaimPricer::REASONS) => spec deduction reason code. Every deduction carries one. */
    public const DEDUCTION_REASONS = [
        'ABOVE_TARIFF' => 'CONTRACT_TARIFF_ADJUSTMENT', 'NO_CONTRACTED_TARIFF' => 'CONTRACT_TARIFF_ADJUSTMENT', 'NOT_ELIGIBLE' => 'NON_COVERED_SERVICE',
        'NOT_COVERED' => 'NON_COVERED_SERVICE', 'NOT_MEDICALLY_NECESSARY' => 'OTHER_REVIEWED_REASON', 'DUPLICATE' => 'DUPLICATE_LINE', 'NO_PREAUTH' => 'UNAUTHORIZED_SERVICE',
        'BENEFIT_LIMIT' => 'BENEFIT_LIMIT_APPLIED', 'DOCUMENTATION' => 'MISSING_DOCUMENTATION', 'OTHER' => 'OTHER_REVIEWED_REASON', 'COPAY' => 'COPAY_APPLIED',
    ];

    public const DISPUTE_REASONS = ['TARIFF_DIFFERENCE', 'SERVICE_NOT_COVERED', 'MISSING_PREAUTH', 'DUPLICATE_BILLING', 'INCORRECT_CODING', 'LATE_SUBMISSION', 'PAYMENT_DELAY', 'OTHER'];

    /** Eligibility engine reason code => spec failure state. */
    public const FAILURE_STATES = [
        'MEMBER_NOT_FOUND' => 'MEMBER_NOT_FOUND', 'MEMBER_POLICY_MISMATCH' => 'MULTIPLE_MATCHES', 'MEMBER_NOT_COVERED_ON_DATE' => 'POLICY_INACTIVE',
        'MEMBER_SUSPENDED' => 'POLICY_INACTIVE', 'GROUP_MEMBER_NOT_ON_SCHEDULE' => 'POLICY_INACTIVE', 'POLICY_NOT_ISSUED' => 'POLICY_INACTIVE', 'POLICY_LAPSED' => 'POLICY_INACTIVE',
        'POLICY_CANCELLED' => 'POLICY_INACTIVE', 'POLICY_SUSPENDED' => 'POLICY_INACTIVE', 'OUTSIDE_POLICY_PERIOD' => 'POLICY_INACTIVE', 'NO_POLICY_VERSION' => 'MANUAL_VERIFICATION_REQUIRED',
        'SERVICE_UNKNOWN' => 'MANUAL_VERIFICATION_REQUIRED', 'BENEFIT_NOT_MAPPED' => 'BENEFIT_NOT_COVERED', 'COVERAGE_NOT_IN_FORCE' => 'BENEFIT_NOT_COVERED',
        'WAITING_PERIOD' => 'BENEFIT_NOT_COVERED', 'BENEFIT_EXHAUSTED' => 'BENEFIT_EXHAUSTED', 'NETWORK_NOT_CONFIGURED' => 'MANUAL_VERIFICATION_REQUIRED',
        'PROVIDER_NOT_IN_NETWORK' => 'PROVIDER_OUT_OF_NETWORK',
    ];

    public const SEARCH_METHODS = ['HEALTH_ID', 'QR_CODE', 'MEMBERSHIP_NUMBER', 'POLICY_NUMBER', 'NATIONAL_ID_OR_OTHER_ALLOWED_IDENTIFIER', 'NAME_PLUS_DATE_OF_BIRTH'];

    /** Spec event => outbox event name the platform emits. */
    public const EVENTS = [
        'EligibilityChecked' => 'provider_portal.eligibility.checked', 'EligibilityConfirmed' => 'provider_portal.eligibility.checked', 'EligibilityFailed' => 'provider_portal.eligibility.checked',
        'PreauthorizationSubmitted' => 'health.preauth.requested', 'PreauthorizationQueryRaised' => 'health.preauth.info_requested',
        'PreauthorizationApproved' => 'health.preauth.approved', 'PreauthorizationPartiallyApproved' => 'health.preauth.partially_approved', 'PreauthorizationRejected' => 'health.preauth.declined',
        'AdmissionAuthorized' => 'health.preauth.approved', 'AdmissionStarted' => 'health.preauth.admitted', 'AdmissionExtensionRequested' => 'health.preauth.extension_requested',
        'AdmissionExtensionApproved' => 'health.preauth.extension_decided', 'PatientDischarged' => 'health.preauth.discharged',
        'TreatmentEpisodeCompleted' => 'provider_portal.treatment_episode.closed', 'ProviderClaimCreated' => 'provider_portal.claim.created',
        'ProviderClaimSubmitted' => 'health.provider_claim.submitted', 'ProviderClaimQueryRaised' => 'provider_portal.claim.query_responded',
        'ProviderClaimApproved' => 'health.provider_claim.adjudicated', 'ProviderClaimPartiallyApproved' => 'health.provider_claim.adjudicated',
        'ProviderClaimRejected' => 'health.provider_claim.adjudicated', 'ProviderClaimBecamePayable' => 'health.provider_claim.payable',
        'ProviderSettlementCalculated' => 'health.provider_settlement.created', 'ProviderSettlementPaid' => 'health.provider_claim.paid',
        'ProviderPaymentReconciled' => 'provider_portal.reconciliation.matched', 'ProviderDisputeOpened' => 'provider_portal.dispute.opened',
        'ProviderDisputeResolved' => 'provider_portal.dispute.resolved',
    ];

    /** Offline: drafts may be captured offline; these may never be finalised offline (server-side only). */
    public const OFFLINE_DRAFT_ALLOWED = ['PREAUTH_DRAFT', 'CLAIM_DRAFT', 'INVOICE_DRAFT', 'DOCUMENT_CAPTURE'];

    public const OFFLINE_FINALIZATION_FORBIDDEN = ['ELIGIBILITY_CONFIRMATION', 'PREAUTHORIZATION_APPROVAL', 'CLAIM_DECISION', 'FINANCIAL_SETTLEMENT', 'PAYMENT_RECONCILIATION'];

    public const DOCUMENTS = ['DOC-063', 'DOC-064', 'DOC-065', 'DOC-066', 'DOC-067', 'DOC-068', 'DOC-069', 'DOC-070', 'DOC-071', 'DOC-072', 'DOC-198', 'DOC-215', 'DOC-216'];

    public static function slug(string $screen): string
    {
        return strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $screen), '_'));
    }

    /** @return list<array<string, mixed>> */
    public static function screens(): array
    {
        return array_map(fn (string $name, array $s) => ['screen' => $name, 'key' => self::slug($name), 'page' => $s[0], 'api' => $s[1], 'permission' => $s[2],
            'label' => ['en' => __('provider_workspace.screens.'.self::slug($name), [], 'en'), 'fr' => __('provider_workspace.screens.'.self::slug($name), [], 'fr')],
            'states' => self::UI_STATES], array_keys(self::SCREENS), self::SCREENS);
    }
}
