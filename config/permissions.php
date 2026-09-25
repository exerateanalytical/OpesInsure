<?php

/**
 * Central catalogue of sensitive permission strings introduced by the Wave 9 / Wave 11
 * authorization hotfix, plus the Wave 12 'agent' category below. This is documentation +
 * a single source of truth for tests — `RequirePermission` and `User::hasPermission()`
 * still read permissions from each `roles.permissions` jsonb array at runtime, this file
 * does not grant anything by itself.
 *
 * SYSTEM_ADMIN no longer bypasses business-data permissions (REQ-RBAC-004): see
 * App\Application\Identity\Rbac\PermissionEvaluator and the business_data section below.
 * The local demo seeder (database/seeders/DatabaseSeeder.php) grants every demo account
 * the wildcard '*' permission for exploration purposes EXCEPT the AGENT and BROKER_STAFF
 * demo accounts, which are deliberately given only the specific permission strings their
 * own mobile/back-office surface needs (see DatabaseSeeder::DEMO_ACCOUNTS).
 *
 * 'never_grant_to' below applies to the 'trust' / 'integrations' / 'releases' categories
 * specifically (the Wave 9/11 hotfix's own back-office-only permissions) — real tenant
 * role provisioning must assign those individually and must NOT grant them to customer,
 * agent or broker-staff roles. The 'agent' category is the deliberate exception: those
 * permissions exist specifically to be granted to the AGENT role (see each entry's own
 * 'suggested_roles'), scoped by App\Application\Agents\AgentPartnerResolver ownership
 * checks rather than by withholding the permission itself.
 */
return [

    'trust' => [
        'trust.fraud-alerts.create' => [
            'description' => 'Open a fraud/risk alert (Wave9Controller::alert).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
        'trust.fraud-alerts.decide' => [
            'description' => 'Review and decide a fraud/risk alert (Wave9Controller::decide).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
        'trust.compliance-cases.create' => [
            'description' => 'Open a compliance case (Wave9Controller::openCase).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
        'trust.compliance-cases.transition' => [
            'description' => 'Transition a compliance case status (Wave9Controller::caseTransition).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
        'trust.dsr.receive' => [
            'description' => 'Intake a data-subject request (Wave9Controller::dsr).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
        'trust.dsr.verify' => [
            'description' => 'Verify the identity evidence on a data-subject request (Wave9Controller::verifyDsr).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
        'trust.dsr.resolve' => [
            'description' => 'Resolve (fulfil/reject) a verified data-subject request (Wave9Controller::resolveDsr).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
        'trust.privileged-access.request' => [
            'description' => 'Request a privileged-access grant for a user (Wave9Controller::access).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'COMPLIANCE_ADMIN', 'PLATFORM_ADMIN'],
        ],
        'trust.privileged-access.approve' => [
            'description' => 'Approve a requested privileged-access grant (Wave9Controller::approveAccess). Requester cannot self-approve — enforced in PrivilegedAccessService::approve().',
            'suggested_roles' => ['SYSTEM_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
        'trust.privileged-access.revoke' => [
            'description' => 'Revoke a privileged-access grant (Wave9Controller::revokeAccess).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'COMPLIANCE_ADMIN', 'PLATFORM_ADMIN'],
        ],
        'trust.regulatory-reports.prepare' => [
            'description' => 'Prepare a regulatory report run (Wave9Controller::prepareReport).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
        'trust.regulatory-reports.approve' => [
            'description' => 'Approve a prepared regulatory report run (Wave9Controller::approveReport). Preparer cannot self-approve — enforced in RegulatoryReportingService::approve().',
            'suggested_roles' => ['SYSTEM_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
        'trust.regulatory-reports.submit' => [
            'description' => 'Submit an approved regulatory report run, or record a failed submission attempt (Wave9Controller::submitReport, ::reportFailure).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
        'trust.regulatory-reports.acknowledge' => [
            'description' => 'Record regulator acknowledgement of a submitted report run (Wave9Controller::acknowledgeReport).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
    ],

    'integrations' => [
        'integrations.manage' => [
            'description' => 'Register partner API clients, advance/suspend/reinstate their lifecycle, and register webhook subscriptions.',
            'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN'],
        ],
        'integrations.revoke' => [
            'description' => 'Permanently revoke a partner API connection (terminal — see IntegrationClientLifecycleService::revoke()).',
            'suggested_roles' => ['SYSTEM_ADMIN'],
        ],
    ],

    'releases' => [
        'releases.security-findings.create' => [
            'description' => 'Log a security finding against a release (Wave11Controller::finding).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN'],
        ],
        'releases.recovery-exercises.create' => [
            'description' => 'Log a disaster-recovery exercise (Wave11Controller::recovery).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN'],
        ],
    ],

    /**
     * Agent Mode (Wave 12) — the freelance-agent's OWN mobile app surface,
     * scoped by App\Application\Agents\AgentPartnerResolver to the caller's
     * own AGENT-type Partner. Unlike every category above, these ARE meant
     * to be granted to the AGENT role — that is the whole point of this
     * category — but only to AGENT (never CUSTOMER or BROKER_STAFF, who have
     * their own broker.* surface elsewhere), and always alongside the
     * ownership scoping each service already enforces, never as a
     * substitute for it.
     */
    // Tenant-wide core reads (security remediation, A1): without the permission a
    // caller only sees its own records via App\Application\Identity\OwnershipScope.
    // Staff roles only — customers/agents/brokers/carriers read through their
    // own ownership-scoped mobile endpoints instead.
    'core_read' => [
        'customers.read' => [
            'description' => 'List/read every tenant customer (CustomerController::index/show) instead of only owned records.',
            'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'FINANCE_ADMIN', 'FINANCE_MANAGER', 'CLAIMS_MANAGER', 'CLAIMS_OFFICER'],
        ],
        'policies.read' => [
            'description' => 'List/read every tenant policy (PolicyController::index/show) instead of only owned records.',
            'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'FINANCE_ADMIN', 'FINANCE_MANAGER', 'CLAIMS_MANAGER', 'CLAIMS_OFFICER'],
        ],
        'risk_assets.read' => [
            'description' => 'List/read every tenant risk asset (RiskAssetController::index/show) instead of only owned records.',
            'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'CLAIMS_MANAGER', 'CLAIMS_OFFICER'],
        ],
    ],

    'agent' => [
        'agent.clients.read' => [
            'description' => 'List/view the agent\'s own registered clients (AgentClientController::index/show).',
            'suggested_roles' => ['AGENT'],
        ],
        'agent.clients.manage' => [
            'description' => 'Register a new client on behalf of the agent\'s own Partner org (AgentClientController::store). Origin-locks the client to the caller\'s own Partner — never a caller-supplied one.',
            'suggested_roles' => ['AGENT'],
        ],
        'agent.commissions.read' => [
            'description' => 'View the agent\'s own commission/PartnerStatement history (AgentCommissionController::index).',
            'suggested_roles' => ['AGENT'],
        ],
        'agent.withdrawals.read' => [
            'description' => 'List the agent\'s own commission withdrawal (PartnerPayoutRequest) history (AgentWithdrawalController::index).',
            'suggested_roles' => ['AGENT'],
        ],
        'agent.withdrawals.request' => [
            'description' => 'Request payout of the agent\'s own accrued commission (AgentWithdrawalController::store). Requires step-up TOTP and is velocity-limited to one in-flight request at a time — see AgentWithdrawalService.',
            'suggested_roles' => ['AGENT'],
        ],
        'agent.sync.read' => [
            'description' => 'View the agent\'s own offline-queue history (AgentOfflineQueueController::index).',
            'suggested_roles' => ['AGENT'],
        ],
        'agent.sync.retry' => [
            'description' => 'Explicitly re-attempt a rejected queued offline operation (AgentOfflineQueueController::retry).',
            'suggested_roles' => ['AGENT'],
        ],
        'agent.sync.dispatch' => [
            'description' => 'Replay an allowlisted AGENT offline operation (agent client intake); customer-safe types such as the customer\'s own claim-incident drafts need no permission and are ownership-scoped per SyncOperationDispatchService::REQUIRED_PERMISSION (SyncController::dispatch — see SyncOperationDispatchService::ALLOWLIST).',
            'suggested_roles' => ['AGENT'],
        ],
    ],

    /**
     * Document engine (Phase 1) permissions — security levels are enforced by
     * App\Application\Documents\Engine\DocumentAccessPolicy.
     */
    'documents' => [
        'documents.carrier.upload' => [
            'description' => 'Upload a document on behalf of the insurer (carrier workspace / adjuster reports).',
            'suggested_roles' => ['CARRIER_STAFF', 'CARRIER_ADMIN', 'CARRIER_SUPER_ADMIN', 'ADJUSTER'],
        ],
        'documents.status.request' => [
            'description' => 'Request a document status change (maker side of the document maker-checker).',
            'suggested_roles' => ['CARRIER_ADMIN', 'CARRIER_SUPER_ADMIN', 'SENIOR_UNDERWRITER', 'COMPLIANCE_ADMIN'],
        ],
        'documents.status.approve' => [
            'description' => 'Approve a requested document status change (checker side; requester cannot self-approve).',
            'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
        'documents.templates.manage' => [
            'description' => 'Manage document templates (platform configuration, not business data).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
        'documents.review' => [
            'description' => 'Review/verify submitted documents.',
            'suggested_roles' => ['UNDERWRITER', 'SENIOR_UNDERWRITER', 'COMPLIANCE_ADMIN'],
        ],
        'documents.medical.read' => [
            'description' => 'Read MEDICAL_RESTRICTED documents.',
            'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'SENIOR_UNDERWRITER', 'PROVIDER_DOCTOR'],
        ],
        'documents.financial.read' => [
            'description' => 'Read FINANCIAL_RESTRICTED documents.',
            'suggested_roles' => ['CARRIER_ADMIN', 'CARRIER_SUPER_ADMIN', 'FINANCE_OFFICER', 'REINSURANCE_OFFICER'],
        ],
        'documents.confidential.read' => [
            'description' => 'Read INSURER_CONFIDENTIAL and INTERNAL documents.',
            'suggested_roles' => ['CARRIER_ADMIN', 'CARRIER_SUPER_ADMIN', 'UNDERWRITER', 'SENIOR_UNDERWRITER'],
        ],
        'documents.regulatory.read' => [
            'description' => 'Read REGULATORY documents.',
            'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
    ],

    /** REQ-RBAC-004: platform-admin ≠ business-data access. */
    'platform' => [
        'platform.break-glass.use' => [
            'description' => 'Invoke an APPROVED, in-window privileged_access_grants row to read business data the caller\'s roles do not cover. Every use is written to privileged_access_events and the audit log (App\Application\Identity\Rbac\BreakGlass).',
            'suggested_roles' => ['SYSTEM_ADMIN'],
        ],
        'developer.portal.read' => [
            'description' => 'Developer portal (API docs, credentials, sandbox) — no business data.',
            'suggested_roles' => ['DEVELOPER'],
        ],
    ],

    /** Central approval matrix (REQ-RBAC-006) and governed configuration changes. */
    'approvals' => [
        'approvals.inbox.view' => ['description' => 'View the approval requests inbox.', 'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'FINANCE_MANAGER', 'CLAIMS_MANAGER', 'CARRIER_ADMIN', 'CARRIER_SUPER_ADMIN']],
        'approvals.decide' => ['description' => 'Approve/reject an approval request (maker cannot self-approve).', 'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'FINANCE_MANAGER', 'CLAIMS_MANAGER', 'CARRIER_ADMIN', 'CARRIER_SUPER_ADMIN']],
        'approvals.matrix.view' => ['description' => 'View the approval matrix rules.', 'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'CARRIER_SUPER_ADMIN']],
        'configuration.changes.manage' => ['description' => 'Propose/manage governed configuration changes.', 'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN']],
        'platform.settings.manage' => ['description' => 'Manage organisation/timezone settings.', 'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN']],
    ],

    /** Case, task and SLA engine (REQ-CAS-001, REQ-CAL-001). */
    'cases' => [
        'cases.view' => ['description' => 'View cases, tasks and my work.', 'suggested_roles' => ['PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'CLAIMS_MANAGER', 'FINANCE_MANAGER', 'CARRIER_ADMIN']],
        'cases.manage' => ['description' => 'Open, transition and work cases and tasks.', 'suggested_roles' => ['PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'CLAIMS_MANAGER', 'CARRIER_ADMIN']],
        'cases.assign' => ['description' => 'Assign cases to users or queues.', 'suggested_roles' => ['PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'CLAIMS_MANAGER', 'CARRIER_ADMIN']],
        'cases.decide' => ['description' => 'Record case decisions.', 'suggested_roles' => ['COMPLIANCE_ADMIN', 'CLAIMS_MANAGER', 'CARRIER_ADMIN']],
        'cases.admin' => ['description' => 'Manage case types and queues.', 'suggested_roles' => ['PLATFORM_ADMIN', 'COMPLIANCE_ADMIN']],
        'cases.calendar.manage' => ['description' => 'Manage business hours and calendar exceptions.', 'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN']],
        'cases.restricted.view' => ['description' => 'View restricted cases.', 'suggested_roles' => ['COMPLIANCE_ADMIN']],
        'cases.str.view' => ['description' => 'View suspicious transaction report cases.', 'suggested_roles' => ['COMPLIANCE_ADMIN']],
    ],

    /** KYC on the case engine (REQ-KYC-001..003). kyc.review (maker) and kyc.decide (checker) are separate people per submission. */
    'kyc' => [
        'kyc.view' => ['description' => 'View KYC submissions, requirements, expiring KYC and party KYC status.', 'suggested_roles' => ['COMPLIANCE_ADMIN', 'CARRIER_ADMIN', 'BROKER_ADMIN']],
        'kyc.manage' => ['description' => 'Open staff-assisted KYC, attach documents, submit, start remediation.', 'suggested_roles' => ['COMPLIANCE_ADMIN', 'CARRIER_ADMIN', 'BROKER_ADMIN']],
        'kyc.review' => ['description' => 'Review KYC: start review, request information, set level, recommend (maker).', 'suggested_roles' => ['COMPLIANCE_ADMIN', 'CARRIER_ADMIN']],
        'kyc.screen' => ['description' => 'Record sanctions / PEP screening results (MANUAL mode).', 'suggested_roles' => ['COMPLIANCE_ADMIN']],
        'kyc.decide' => ['description' => 'Confirm or return a KYC recommendation (checker).', 'suggested_roles' => ['COMPLIANCE_ADMIN']],
    ],

    /** Capability profile (REQ-AOM-001), insurer setup (REQ-SET-002), broker setup (REQ-SET-003). Platform configuration, not business data. */
    'capabilities_setup' => [
        'capability_profiles.view' => ['description' => 'View insurer capability profiles, resolved modes, maturity and pins.', 'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'CARRIER_SUPER_ADMIN', 'CARRIER_ADMIN']],
        'capability_profiles.manage' => ['description' => 'Draft/edit/submit a capability profile; pin modes on transactions.', 'suggested_roles' => ['PLATFORM_ADMIN', 'CARRIER_SUPER_ADMIN']],
        'capability_profiles.approve' => ['description' => 'Approve/reject a capability profile (maker cannot approve).', 'suggested_roles' => ['PLATFORM_ADMIN', 'COMPLIANCE_ADMIN']],
        'carrier_setup.view' => ['description' => 'View insurer setup lifecycle and 23-item checklist.', 'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'CARRIER_SUPER_ADMIN']],
        'carrier_setup.manage' => ['description' => 'Open insurer setup, attest checklist items, move DRAFT→…→READY_FOR_APPROVAL.', 'suggested_roles' => ['PLATFORM_ADMIN', 'CARRIER_SUPER_ADMIN']],
        'carrier_setup.approve' => ['description' => 'Regulatory review decisions, activation (maker-checker), suspend/deactivate/terminate.', 'suggested_roles' => ['PLATFORM_ADMIN', 'COMPLIANCE_ADMIN']],
        'partner_setup.view' => ['description' => 'View broker setup lifecycle and 19-item checklist.', 'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'BROKER_ADMIN']],
        'partner_setup.manage' => ['description' => 'Open broker setup, attest checklist items, move DRAFT→…→TESTING.', 'suggested_roles' => ['PLATFORM_ADMIN', 'BROKER_ADMIN']],
        'partner_setup.approve' => ['description' => 'Broker review decisions, activation (maker-checker), suspend/terminate.', 'suggested_roles' => ['PLATFORM_ADMIN', 'COMPLIANCE_ADMIN']],
    ],

    'provider' => [
        'provider.portal.read' => ['description' => 'Open the provider portal (FRP V).', 'suggested_roles' => \App\Application\Identity\RoleCatalogue::PROVIDER_ROLES],
        'provider.eligibility.check' => ['description' => 'Check member eligibility / cover at the point of care.', 'suggested_roles' => ['PROVIDER_FRONT_DESK', 'PROVIDER_DOCTOR', 'PROVIDER_PHARMACY', 'PROVIDER_LAB']],
        'provider.claims.submit' => ['description' => 'Submit a provider (cashless) claim.', 'suggested_roles' => ['PROVIDER_ADMIN', 'PROVIDER_DOCTOR', 'PROVIDER_BILLING', 'PROVIDER_PHARMACY', 'PROVIDER_LAB']],
        'provider.claims.read' => ['description' => 'Read the provider organisation\'s claims.', 'suggested_roles' => ['PROVIDER_ADMIN', 'PROVIDER_BILLING', 'PROVIDER_FINANCE']],
        'provider.finance.read' => ['description' => 'Read provider settlements/remittances.', 'suggested_roles' => ['PROVIDER_ADMIN', 'PROVIDER_FINANCE']],
        'provider.staff.manage' => ['description' => 'Manage provider organisation users.', 'suggested_roles' => ['PROVIDER_ADMIN']],
    ],

    /** Batch 3 / 3E — carrier ↔ broker distribution agreements (REQ-SEED-004 / REQ-DUP-023). */
    'distribution' => [
        'distribution.agreements.view' => ['description' => 'View carrier-broker agreements, product permissions and permit checks.', 'suggested_roles' => ['PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'CARRIER_ADMIN', 'CARRIER_SUPER_ADMIN', 'BROKER_ADMIN']],
        'distribution.agreements.manage' => ['description' => 'Draft carrier-broker agreements and set product permissions/commission.', 'suggested_roles' => ['PLATFORM_ADMIN', 'CARRIER_ADMIN', 'CARRIER_SUPER_ADMIN']],
        'distribution.agreements.approve' => ['description' => 'Activate/suspend/terminate an agreement (maker cannot activate own).', 'suggested_roles' => ['COMPLIANCE_ADMIN', 'CARRIER_SUPER_ADMIN']],
    ],

    'regulator' => [
        'regulator.reports.read' => [
            'description' => 'Read-only regulatory reporting (REGULATOR_READ scope); never grants writes.',
            'suggested_roles' => ['REGULATOR'],
        ],
    ],

    /**
     * REQ-RBAC-004 classification. A permission is BUSINESS DATA when its
     * module (first segment) is listed here, unless it is a platform
     * exception. PLATFORM_ONLY roles (RoleCatalogue::PLATFORM_ONLY) never get
     * business-data permissions from their roles — not even via '*'.
     */
    // Batch 3B — master data ownership (REQ-MDM-006/007) and the generic import pipeline (REQ-IMP-001).
    // Reference data, not business data; the import checker is additionally bound by the approval matrix
    // (action master_data.import.approve: maker ≠ checker).
    'master_data' => [
        'master_data.overrides.manage' => [
            'description' => 'Hide / alias / internal-code platform values and add private values for the own tenant.',
            'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'CARRIER_ADMIN', 'BROKER_ADMIN'],
        ],
        'master_data.mappings.manage' => [
            'description' => 'Map canonical values to the own insurer / broker codes (carrier_ and broker_master_data_mappings).',
            'suggested_roles' => ['CARRIER_ADMIN', 'BROKER_ADMIN'],
        ],
        'master_data.merge.request' => [
            'description' => 'See duplicate groups and request a merge (entity.merge maker).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
        'master_data.merge.approve' => [
            'description' => 'Approve or reject a requested merge (entity.merge checker).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
        'imports.create' => [
            'description' => 'Upload, map, preview, submit and cancel import batches.',
            'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN'],
        ],
        'imports.approve' => [
            'description' => 'Approve or reject a submitted import batch (checker).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
    ],

    // Batch 4A — party golden record (REQ-PTY-002/003/004). Business data (module 'parties'). Reads use the
    // existing parties.manage; merges are additionally bound by the approval matrix (entity.merge: maker ≠ checker).
    'party_golden_record' => [
        'parties.roles.manage' => [
            'description' => 'Assign and end bitemporal party roles (policyholder, insured, beneficiary, payer …).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'CARRIER_ADMIN', 'BROKER_ADMIN', 'UNDERWRITER'],
        ],
        'parties.relationships.manage' => [
            'description' => 'Record / end party relationships and ownership interests (UBO graph).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'COMPLIANCE_ADMIN', 'CARRIER_ADMIN', 'BROKER_ADMIN'],
        ],
        'parties.match.review' => [
            'description' => 'Run duplicate scans, review and dismiss probable-match candidates (data steward).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'COMPLIANCE_ADMIN', 'DATA_STEWARD'],
        ],
        'parties.merge.request' => [
            'description' => 'Request a party merge with survivorship rules (entity.merge maker).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'COMPLIANCE_ADMIN', 'DATA_STEWARD'],
        ],
        'parties.merge.approve' => [
            'description' => 'Approve / reject a party merge and reverse (unmerge) an applied one (entity.merge checker).',
            'suggested_roles' => ['SYSTEM_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
    ],

    'crm' => [
        // Batch 4C — REQ-CRM-001/003/004 (routes/crm.php).
        'crm.leads.read' => [
            'description' => 'List/view the lead directory and its activities (a broker user sees only its own firm leads).',
            'suggested_roles' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER'],
        ],
        'crm.leads.manage' => [
            'description' => 'Create leads, move them through the pipeline and log activities/follow-ups.',
            'suggested_roles' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER'],
        ],
        'crm.leads.assign' => [
            'description' => 'Assign/reassign leads to an intermediary and/or a user (brokers only within their own firm).',
            'suggested_roles' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BRANCH_MANAGER'],
        ],
        'attribution.transfer' => [
            'description' => 'Preview and execute a portfolio transfer between intermediaries (WF-079).',
            'suggested_roles' => ['BROKER_ADMIN', 'COMPLIANCE_ADMIN'],
        ],
        'beneficiaries.read' => [
            'description' => 'View a policy current beneficiary designations and their history.',
            'suggested_roles' => ['BROKER_ADMIN', 'BROKER_STAFF', 'CARRIER_ADMIN', 'CLAIMS_OFFICER'],
        ],
        'beneficiaries.manage' => [
            'description' => 'Replace a policy beneficiary designations (versioned; allocations must total 100 %).',
            'suggested_roles' => ['BROKER_ADMIN', 'BROKER_STAFF', 'CARRIER_ADMIN'],
        ],
    ],

    // Batch 4D — REQ-SRC-001 global search: each entity is searched only with its read permission
    // (customers.read, policies.read, claims.view, risk_assets.read and the two below), then narrowed
    // by DataScopeResolver. A caller whose widest scope is OWN searches only their own rows.
    'risks_search' => [
        'quotes.read' => [
            'description' => 'Read quote requests and offers; includes quotes in GET /api/v1/search.',
            'suggested_roles' => ['BRANCH_MANAGER', 'CUSTOMER_SERVICE', 'UNDERWRITER'],
        ],
        'documents.read' => [
            'description' => 'Read documents (restricted levels also need documents.medical/financial/confidential/regulatory.read); includes documents in GET /api/v1/search.',
            'suggested_roles' => ['CUSTOMER_SERVICE', 'CLAIMS_OFFICER', 'UNDERWRITER'],
        ],
    ],

    'business_data' => [
        'modules' => [
            'customers', 'policies', 'risk_assets', 'claims', 'carrier', 'broker', 'agent', 'provider', 'payout', 'settlement',
            'commission', 'ledger', 'reconciliation', 'refund', 'statements', 'bordereaux', 'underwriting', 'quotes', 'proposals',
            'parties', 'partners', 'partner', 'payments', 'renewals', 'documents', 'fraud', 'stickers', 'privacy', 'reports',
            'regulator', 'fulfilment', 'fulfilments', 'support', 'cases', 'distribution', 'kyc', 'crm', 'beneficiaries',
        ],
        'permissions' => ['trust.dsr.receive', 'trust.dsr.verify', 'trust.dsr.resolve', 'attribution.transfer'],
        'platform_exceptions' => ['documents.templates.manage', 'cases.calendar.manage', 'cases.admin'],
    ],

    'never_grant_to' => ['CUSTOMER', 'AGENT', 'BROKER_STAFF'],
];
