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
        'kyc.screen' => ['description' => 'Record sanctions / PEP screening results and open rescreening rounds (MANUAL_AUDITED mode; never automated).', 'suggested_roles' => ['COMPLIANCE_ADMIN']],
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
        // Batch 5 / 5D — REQ-DST-001/002, REQ-AOM-002: sellable catalogue per viewer, sellability checks, execution plan.
        'distribution.catalogue.view' => ['description' => 'View the sellable catalogue and sellability of carrier products for own partner (or tenant partners), and the execution adapters.', 'suggested_roles' => ['PLATFORM_ADMIN', 'BROKER_ADMIN', 'BROKER_STAFF', 'AGENT', 'BRANCH_MANAGER', 'CARRIER_ADMIN']],
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
        // Owner Workflow Data Master v1 (reference lists): catalogue statuses, read-only.
        'master_data.workflow_status.view' => [
            'description' => 'See owner workflow data master catalogue statuses (PENDING_SOURCE, UNVERIFIED, …) and their value counts.',
            'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN'],
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

    // Batch 5A — product model (REQ-PRD-001…006): routes/product_model.php. Product configuration, not customer data.
    'catalogue' => [
        'catalogue.view' => ['description' => 'Read product families, carrier products, versions, hierarchy, snapshots, legal texts and indemnity previews (insurers: own carrier only).', 'suggested_roles' => ['PLATFORM_ADMIN', 'CARRIER_ADMIN', 'CARRIER_STAFF', 'UNDERWRITER', 'COMPLIANCE_ADMIN']],
        'catalogue.manage' => ['description' => 'Create families, carrier products, draft versions, plans, coverage terms, limits, deductibles, exclusions and draft legal texts.', 'suggested_roles' => ['PLATFORM_ADMIN', 'CARRIER_ADMIN']],
        'catalogue.publish' => ['description' => 'Checker: approve / publish / suspend / reinstate / retire versions and approve legal texts (maker-checker enforced).', 'suggested_roles' => ['PLATFORM_ADMIN', 'CARRIER_SUPER_ADMIN', 'COMPLIANCE_ADMIN']],
    ],

    // Batch 6A — product governance + sandbox (REQ-PRD-007…010): routes/product_governance.php. Product configuration, not customer data.
    'catalogue_governance' => [
        'catalogue.review' => ['description' => 'Governance reviewer: complete the technical and compliance review steps of a product version, or reject it (never the maker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'UNDERWRITER', 'COMPLIANCE_ADMIN']],
        'catalogue.test' => ['description' => 'Maintain a product version test policy pack and run it in the sandbox (rules + rating + documents, no business records written).', 'suggested_roles' => ['PLATFORM_ADMIN', 'CARRIER_ADMIN', 'ACTUARY', 'UNDERWRITER']],
    ],

    /** Batch 5C — rating v2 (REQ-RAT-001..005). tariff.manage / tariff.approve already gate create/submit/approve in routes/api.php. */
    'rating' => [
        'tariff.manage' => ['description' => 'Create, submit and view tariff versions and their status history.', 'suggested_roles' => ['CARRIER_ADMIN', 'ACTUARY']],
        'tariff.approve' => ['description' => 'Approve or reject a tariff version in review (checker; never the maker).', 'suggested_roles' => ['CARRIER_ADMIN']],
        'tariff.publish' => ['description' => 'Schedule, activate or expire an approved tariff version (PRE §75).', 'suggested_roles' => ['CARRIER_ADMIN']],
        'rating.charges.view' => ['description' => 'View the charge-code catalogue and tax/levy/fee tables (rates DEMO/UNVERIFIED until OQ-9).', 'suggested_roles' => ['CARRIER_ADMIN', 'FINANCE_ADMIN', 'COMPLIANCE_ADMIN']],
        'rating.charges.manage' => ['description' => 'Draft tax/levy/fee table versions.', 'suggested_roles' => ['FINANCE_ADMIN']],
        'rating.charges.approve' => ['description' => 'Approve a tax/levy/fee table version (checker; never the maker).', 'suggested_roles' => ['FINANCE_ADMIN', 'COMPLIANCE_ADMIN']],
        'rating.charges.verify' => ['description' => 'Confirm or reject the legal basis / source verification of tax/levy/fee rates (owner decision 10; checker, never the requester).', 'suggested_roles' => ['COMPLIANCE_ADMIN']],
        'rating.runs.view' => ['description' => 'View a quote rating snapshot (versions, EngineResult, branch allocation) and reproduce it.', 'suggested_roles' => ['UNDERWRITER', 'CARRIER_ADMIN', 'COMPLIANCE_ADMIN']],
    ],

    // Batch 6D — proposal workflow (REQ-PRP-001…005): routes/proposals.php. Proposers act on their own proposals without these;
    // the information request uses the existing underwriting.decide permission.
    'proposals' => [
        'proposals.issuability.read' => ['description' => 'View the POLICY_ISSUABLE evaluation (approval, payment condition, documents, underwriting, KYC) of a proposal.', 'suggested_roles' => ['UNDERWRITER', 'SENIOR_UNDERWRITER', 'CARRIER_ADMIN', 'FINANCE_ADMIN', 'COMPLIANCE_ADMIN']],
    ],

    // Batch 6B — quote workflow (REQ-QUO-001…005, REQ-DST-003): routes/quotes.php. Customers act on their own quotes without these.
    'quotes' => [
        'quotes.manage' => ['description' => 'Amend, generate, decline or cancel tenant quotes on behalf of customers (WF-010…014).', 'suggested_roles' => ['BROKER_ADMIN', 'BROKER_STAFF', 'AGENT', 'CUSTOMER_SERVICE', 'UNDERWRITER']],
        'quotes.send' => ['description' => 'Send/share a quotation to the customer (WF-012); creates a tracked share link.', 'suggested_roles' => ['BROKER_ADMIN', 'BROKER_STAFF', 'AGENT', 'CUSTOMER_SERVICE']],
        'quotes.premium_override.request' => ['description' => 'Request a premium override on a quote offer (maker; BRK-035). Applies only after approval.', 'suggested_roles' => ['BROKER_ADMIN', 'BROKER_STAFF', 'UNDERWRITER']],
        'quotes.premium_override.approve' => ['description' => 'Approve/reject a premium override (checker; never the requester) and apply an approved one.', 'suggested_roles' => ['BROKER_ADMIN', 'CARRIER_ADMIN', 'UNDERWRITER']],
    ],

    // Batch 5B — rules engine + question sets (REQ-RUL-001…004, REQ-DUP-020): routes/rules.php. Product configuration, not customer data.
    'rules' => [
        'rules.view' => ['description' => 'Read rule sets, question sets, product questionnaires; sandbox-simulate a rule set (nothing persisted).', 'suggested_roles' => ['PLATFORM_ADMIN', 'CARRIER_ADMIN', 'UNDERWRITER', 'COMPLIANCE_ADMIN']],
        'rules.manage' => ['description' => 'Draft and submit eligibility / completeness rule sets and question sets (maker).', 'suggested_roles' => ['PLATFORM_ADMIN', 'CARRIER_ADMIN']],
        'rules.approve' => ['description' => 'Approve, reject or retire rule sets and question sets (checker; never the maker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'COMPLIANCE_ADMIN']],
        'rules.evaluate' => ['description' => 'Run eligibility and completeness checks (POST /insurance/eligibility/check, /insurance/completeness/check); logged in engine_evaluations.', 'suggested_roles' => ['UNDERWRITER', 'BROKER_ADMIN', 'BROKER_STAFF', 'CARRIER_ADMIN']],
    ],

    // Batch 6C — manual quotation, AOM Mode 1 (REQ-QUO-006): routes/carrier_quote_requests.php. Customer quote data
    // (modules 'quotes' / 'carrier' are already business data).
    'carrier_quote_requests' => [
        'quotes.carrier_requests.view' => ['description' => 'List the manual quotation requests sent to insurers for a quote.', 'suggested_roles' => ['BROKER_ADMIN', 'BROKER_STAFF', 'AGENT', 'PLATFORM_ADMIN']],
        'quotes.carrier_requests.create' => ['description' => 'Send a quote to an insurer whose quotation capability is MANUAL; cancel an open request.', 'suggested_roles' => ['BROKER_ADMIN', 'BROKER_STAFF', 'AGENT', 'PLATFORM_ADMIN']],
        'quotes.carrier_requests.record_on_behalf' => ['description' => 'Record an insurer offer/decline on its behalf, with the insurer\'s written answer as evidence.', 'suggested_roles' => ['BROKER_ADMIN']],
        'carrier.quote_requests.view' => ['description' => 'Insurer work queue of manual quotation requests (own carrier only).', 'suggested_roles' => ['CARRIER_ADMIN', 'CARRIER_STAFF', 'UNDERWRITER']],
        'carrier.quote_requests.respond' => ['description' => 'Insurer staff: take a request, enter the offer (premium breakdown, conditions, validity, documents) or decline.', 'suggested_roles' => ['CARRIER_ADMIN', 'CARRIER_STAFF', 'UNDERWRITER']],
    ],

    // Owner decisions 2026-09-25 (#12 authority types, #17 premium-to-cover, institutional datasets): routes/owner_decisions.php.
    // Case-engine additions (families, sub-types, SLA overrides, calendar breaks) reuse cases.view/manage/admin/calendar.manage.
    'owner_decisions' => [
        'authority.types.view' => ['description' => 'View the authority type catalogue.', 'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'CARRIER_ADMIN', 'CARRIER_SUPER_ADMIN']],
        'authority.types.manage' => ['description' => 'Add or retire authority types (extensible catalogue).', 'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN']],
        'premium_cover.rules.view' => ['description' => 'View premium-to-cover rules and evaluate cover status.', 'suggested_roles' => ['PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'CARRIER_ADMIN', 'CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'premium_cover.rules.manage' => ['description' => 'Draft or retire premium-to-cover rules (maker).', 'suggested_roles' => ['PLATFORM_ADMIN', 'CARRIER_SUPER_ADMIN']],
        'premium_cover.rules.approve' => ['description' => 'Approve premium-to-cover rules (checker; never the maker).', 'suggested_roles' => ['COMPLIANCE_ADMIN', 'CARRIER_SUPER_ADMIN']],
        'reference_datasets.view' => ['description' => 'View public holiday and hazard zone datasets.', 'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'UNDERWRITER', 'CARRIER_ADMIN']],
        'reference_datasets.manage' => ['description' => 'Draft a new dataset version (source, jurisdiction, dates, entries).', 'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN']],
        'reference_datasets.approve' => ['description' => 'Activate or retire a dataset version (checker; never the maker).', 'suggested_roles' => ['SYSTEM_ADMIN', 'COMPLIANCE_ADMIN']],
    ],

    /** Official insurer institutional directory (platform master data, not business data). */
    'institution_directory' => [
        'directory.institutions.manage' => ['description' => 'Edit insurer directory contacts, HQ, branches and verification status (audited).', 'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN']],
        'directory.verification_labels.manage' => ['description' => 'Edit the EN/FR display labels of verification statuses.', 'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN']],
    ],

    // Workflow Institutional Data Master v1 — Data Readiness registry (routes/data_readiness.php). Platform configuration status, not business data.
    'data_readiness' => [
        'data_readiness.view' => ['description' => 'View the Data Readiness registry: every data-master domain with its status, owner, source and what is missing.', 'suggested_roles' => ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN']],
    ],

    // Batch 7D — issuance operations (routes/issuance_ops.php): REQ-POL-004 issuance exception queue, REQ-POL-007 sticker custody chain.
    'issuance_ops' => [
        'policies.issuance_queue.view' => ['description' => 'List failed / paid-not-issued issuance exceptions and their trail.', 'suggested_roles' => ['CARRIER_ADMIN', 'CARRIER_STAFF', 'BROKER_ADMIN', 'BRANCH_MANAGER', 'FINANCE_OFFICER', 'FINANCE_MANAGER']],
        'policies.issuance_queue.manage' => ['description' => 'Scan for paid-not-issued payments, retry the issuance request, escalate an exception.', 'suggested_roles' => ['CARRIER_ADMIN', 'BROKER_ADMIN', 'FINANCE_OFFICER', 'FINANCE_MANAGER']],
        'policies.issuance_queue.resolve' => ['description' => 'Close an issuance exception (refund requested / resolved manually) with notes.', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'stickers.view' => ['description' => 'Sticker inventory by custody level, custody history, handovers.', 'suggested_roles' => ['CARRIER_ADMIN', 'CARRIER_STAFF', 'BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER', 'AGENT']],
        'stickers.handover' => ['description' => 'Initiate, accept, reject or cancel a sticker handover (receiver acknowledges; never the initiator).', 'suggested_roles' => ['CARRIER_ADMIN', 'BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER', 'AGENT']],
        'stickers.allocate' => ['description' => 'Allocate stickers down the chain broker → branch → agent (agents may only return their own).', 'suggested_roles' => ['CARRIER_ADMIN', 'BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BRANCH_MANAGER']],
        'stickers.allocate.carrier' => ['description' => 'Release carrier sticker stock to a broker, or take it back.', 'suggested_roles' => ['CARRIER_ADMIN']],
        'stickers.reconcile' => ['description' => 'Record a physical sticker count against the custody ledger (missing / damaged).', 'suggested_roles' => ['CARRIER_ADMIN', 'BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BRANCH_MANAGER']],
        'stickers.assign' => ['description' => 'Assign an in-stock sticker to an in-force motor policy.', 'suggested_roles' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER', 'AGENT']],
        'stickers.assign.any' => ['description' => 'Assign a sticker held by another agent.', 'suggested_roles' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR']],
        'stickers.receive' => ['description' => 'Receive a printed sticker batch into carrier stock (CertificateController::receiveBatch).', 'suggested_roles' => ['CARRIER_ADMIN']],
    ],

    // Batch 7 — provider master / networks / tariffs (routes/api.php providers group). Provider master data.
    'providers' => [
        'providers.view' => ['description' => 'List and read healthcare providers and their credentialing state.', 'suggested_roles' => ['CARRIER_ADMIN', 'CARRIER_STAFF', 'CLAIMS_OFFICER', 'CLAIMS_MANAGER']],
        'providers.manage' => ['description' => 'Create and edit healthcare providers, sites and contacts.', 'suggested_roles' => ['CARRIER_ADMIN', 'CLAIMS_MANAGER']],
        'providers.credential' => ['description' => 'Move a provider through credentialing (verify, suspend, reinstate).', 'suggested_roles' => ['CARRIER_ADMIN', 'CLAIMS_MANAGER']],
        'provider_networks.view' => ['description' => 'Read provider networks, memberships and tariffs.', 'suggested_roles' => ['CARRIER_ADMIN', 'CARRIER_STAFF', 'CLAIMS_OFFICER', 'CLAIMS_MANAGER']],
        'provider_networks.manage' => ['description' => 'Maintain provider networks, memberships and draft tariffs (maker).', 'suggested_roles' => ['CARRIER_ADMIN', 'CLAIMS_MANAGER']],
        'provider_tariffs.approve' => ['description' => 'Approve a provider tariff (checker; never the maker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'CLAIMS_MANAGER']],
    ],

    // Batch 7 — coinsurance arrangements and apportionment (routes/api.php coinsurance group).
    'coinsurance' => [
        'coinsurance.view' => ['description' => 'Read coinsurance arrangements, shares and apportionments.', 'suggested_roles' => ['UNDERWRITER', 'SENIOR_UNDERWRITER', 'CARRIER_SUPER_ADMIN', 'REINSURANCE_OFFICER', 'FINANCE_OFFICER', 'FINANCE_MANAGER']],
        'coinsurance.manage' => ['description' => 'Create or terminate a coinsurance arrangement and its participant shares (maker).', 'suggested_roles' => ['UNDERWRITER', 'SENIOR_UNDERWRITER']],
        'coinsurance.approve' => ['description' => 'Activate a coinsurance arrangement (checker; never the maker).', 'suggested_roles' => ['SENIOR_UNDERWRITER', 'CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'coinsurance.apportion' => ['description' => 'Apportion premium / claims across coinsurance participants.', 'suggested_roles' => ['UNDERWRITER', 'SENIOR_UNDERWRITER', 'FINANCE_OFFICER', 'FINANCE_MANAGER']],
    ],

    // Batch 7 — reinsurance reinsurers, treaties and cessions (routes/api.php reinsurance group).
    'reinsurance' => [
        'reinsurance.reinsurers.manage' => ['description' => 'Register reinsurers / reinsurance brokers and change their status.', 'suggested_roles' => ['REINSURANCE_OFFICER']],
        'reinsurance.treaties.view' => ['description' => 'Read treaties and treaty versions.', 'suggested_roles' => ['REINSURANCE_OFFICER', 'CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'reinsurance.treaties.manage' => ['description' => 'Create treaties and draft treaty versions (maker).', 'suggested_roles' => ['REINSURANCE_OFFICER']],
        'reinsurance.treaties.approve' => ['description' => 'Activate a treaty version (checker; never the maker).', 'suggested_roles' => ['REINSURANCE_OFFICER', 'CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'reinsurance.cessions.view' => ['description' => 'Read policy cessions.', 'suggested_roles' => ['REINSURANCE_OFFICER', 'CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'reinsurance.cessions.calculate' => ['description' => 'Calculate and record the cessions of a policy.', 'suggested_roles' => ['REINSURANCE_OFFICER']],
    ],

    // Batch 8 — special policies (cargo, life surrender), portfolio transfer / portability, document governance,
    // and the policy lifecycle (cancellation, suspension, reinstatement, recovery, waiver). Maker vs checker:
    // *.request / *.manage sit with staff and officers, *.approve with senior / manager roles.
    'special_policies' => [
        'special_policies.view' => ['description' => 'Read special policies (open cover, schedules, declarations).', 'suggested_roles' => ['CARRIER_ADMIN', 'CARRIER_STAFF', 'UNDERWRITER', 'CUSTOMER_SERVICE', 'BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER']],
        'special_policies.manage' => ['description' => 'Set up and edit special policy terms.', 'suggested_roles' => ['CARRIER_ADMIN', 'SENIOR_UNDERWRITER']],
        'special_policies.schedule.manage' => ['description' => 'Maintain the schedule (insured items / members) of a special policy.', 'suggested_roles' => ['CARRIER_ADMIN', 'UNDERWRITER', 'BROKER_ADMIN']],
        'cargo_declarations.declare' => ['description' => 'Declare a shipment against an open cargo cover.', 'suggested_roles' => ['CARRIER_STAFF', 'UNDERWRITER', 'BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF']],
        'cargo_declarations.cancel' => ['description' => 'Cancel a cargo declaration.', 'suggested_roles' => ['CARRIER_ADMIN', 'SENIOR_UNDERWRITER', 'BROKER_ADMIN']],
        'life_surrender.scales.manage' => ['description' => 'Draft life surrender value scales (maker).', 'suggested_roles' => ['CARRIER_ADMIN', 'UNDERWRITER']],
        'life_surrender.scales.approve' => ['description' => 'Approve / activate a life surrender scale (checker, never the maker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'SENIOR_UNDERWRITER']],
        'life_surrender.quote' => ['description' => 'Quote a life policy surrender value.', 'suggested_roles' => ['CARRIER_STAFF', 'UNDERWRITER', 'CUSTOMER_SERVICE', 'FINANCE_OFFICER']],
    ],

    'policy_portfolio' => [
        'policies.portfolio_transfer.read' => ['description' => 'Read portfolio transfer requests for policies in scope.', 'suggested_roles' => ['CARRIER_ADMIN', 'CARRIER_STAFF', 'CUSTOMER_SERVICE', 'BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER', 'AGENT']],
        'policies.portfolio_transfer.request' => ['description' => 'Request the transfer of a policy portfolio to another intermediary (maker).', 'suggested_roles' => ['CARRIER_ADMIN', 'BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BRANCH_MANAGER']],
        'policies.portfolio_transfer.approve' => ['description' => 'Approve or reject a portfolio transfer (checker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN']],
        'policies.portability.export' => ['description' => 'Export a policy portability pack for the customer or a new insurer.', 'suggested_roles' => ['CARRIER_ADMIN', 'BROKER_ADMIN']],
    ],

    'document_governance' => [
        'documents.intake.manage' => ['description' => 'Run the document intake queue (classify, link, reject).', 'suggested_roles' => ['CARRIER_STAFF', 'CUSTOMER_SERVICE', 'BROKER_ADMIN', 'BROKER_SUPERVISOR']],
        'documents.access_log.read' => ['description' => 'Read who accessed a document and when.', 'suggested_roles' => ['COMPLIANCE_ADMIN', 'CARRIER_ADMIN']],
        'documents.retention.manage' => ['description' => 'Draft retention policies and schedules (maker).', 'suggested_roles' => ['COMPLIANCE_ADMIN', 'CARRIER_ADMIN']],
        'documents.retention.approve' => ['description' => 'Approve a retention policy (checker).', 'suggested_roles' => ['COMPLIANCE_ADMIN', 'CARRIER_SUPER_ADMIN']],
        'documents.legal_hold.manage' => ['description' => 'Place and release legal holds on documents.', 'suggested_roles' => ['COMPLIANCE_ADMIN', 'CARRIER_ADMIN']],
        'documents.destruction.request' => ['description' => 'Request destruction of documents past retention (maker).', 'suggested_roles' => ['COMPLIANCE_ADMIN', 'CARRIER_ADMIN']],
        'documents.destruction.approve' => ['description' => 'Approve a destruction request (checker, never the requester).', 'suggested_roles' => ['COMPLIANCE_ADMIN', 'CARRIER_SUPER_ADMIN']],
        'documents.signatures.manage' => ['description' => 'Request and track electronic signatures on documents.', 'suggested_roles' => ['CARRIER_STAFF', 'UNDERWRITER', 'BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF']],
    ],

    'policy_lifecycle' => [
        'policies.cancellation.request' => ['description' => 'Request the cancellation of a policy (brokers/agents: own policies only).', 'suggested_roles' => ['CARRIER_STAFF', 'CUSTOMER_SERVICE', 'BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER', 'AGENT']],
        'policies.cancellation.review' => ['description' => 'Review a cancellation request (refund / notice computation).', 'suggested_roles' => ['CARRIER_ADMIN', 'UNDERWRITER']],
        'policies.cancellation.approve' => ['description' => 'Approve a policy cancellation (checker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'SENIOR_UNDERWRITER']],
        'policies.suspend' => ['description' => 'Suspend cover on a policy.', 'suggested_roles' => ['CARRIER_ADMIN', 'SENIOR_UNDERWRITER']],
        'policies.reinstatement.request' => ['description' => 'Request reinstatement of a suspended / lapsed policy (brokers/agents: own policies only).', 'suggested_roles' => ['CARRIER_STAFF', 'UNDERWRITER', 'CUSTOMER_SERVICE', 'BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER', 'AGENT']],
        'policies.reinstatement.approve' => ['description' => 'Approve a reinstatement (checker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'SENIOR_UNDERWRITER']],
        'policy.recovery.request' => ['description' => 'Request recovery of a lapsed policy after premium default.', 'suggested_roles' => ['CARRIER_STAFF', 'UNDERWRITER', 'FINANCE_OFFICER', 'BROKER_ADMIN']],
        'policy.recovery.approve' => ['description' => 'Approve a policy recovery (checker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'SENIOR_UNDERWRITER', 'FINANCE_MANAGER']],
        'policy.premium.waive' => ['description' => 'Waive an overdue premium instalment.', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
    ],

    // Batch 9 money chain (obligations, allocations, premium status, refunds, clearing, cashier, FX, statements) and
    // Batch 10-10 exception centre + finance reports. Maker vs checker: approve / reconcile / reverse / close / rule and
    // FX changes sit with CARRIER_SUPER_ADMIN, BRANCH_MANAGER (cashier supervisor) and the FINANCE_MANAGER / FINANCE_ADMIN wildcards.
    'finance_money_chain' => [
        'finance.obligations.view' => ['description' => 'Read financial obligations, aging and instalment schedules.', 'suggested_roles' => ['FINANCE_OFFICER', 'CARRIER_ADMIN', 'BROKER_ADMIN', 'BRANCH_MANAGER']],
        'finance.obligations.manage' => ['description' => 'Write off or cancel an obligation (checker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'payments.allocations.read' => ['description' => 'Read payment allocations and the active allocation rule.', 'suggested_roles' => ['FINANCE_OFFICER', 'CARRIER_ADMIN']],
        'payments.allocations.manage' => ['description' => 'Allocate a payment across premium components / obligations (maker).', 'suggested_roles' => ['FINANCE_OFFICER']],
        'payments.allocations.reverse' => ['description' => 'Reverse an allocation run (checker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'finance.allocation_rules.manage' => ['description' => 'Publish a new allocation rule version.', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'premium_status.read' => ['description' => 'Read a policy premium status and components.', 'suggested_roles' => ['FINANCE_OFFICER', 'CARRIER_STAFF', 'CUSTOMER_SERVICE', 'BROKER_STAFF', 'BROKER_SUPERVISOR', 'BROKER_ADMIN', 'BRANCH_MANAGER']],
        'premium_components.manage' => ['description' => 'Record premium components (maker).', 'suggested_roles' => ['FINANCE_OFFICER']],
        'premium_components.close' => ['description' => 'Close / waive a premium component (checker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'refund.view' => ['description' => 'Read the refund queue.', 'suggested_roles' => ['FINANCE_OFFICER', 'CARRIER_ADMIN', 'CUSTOMER_SERVICE']],
        'refund.review' => ['description' => 'Review a calculated refund.', 'suggested_roles' => ['FINANCE_OFFICER']],
        'refund.pay' => ['description' => 'Record the payout of an approved refund.', 'suggested_roles' => ['FINANCE_OFFICER']],
        'refund.reconcile' => ['description' => 'Reconcile a paid refund against the bank / provider (checker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'clearing.view' => ['description' => 'Read mobile-money clearing batches and suspense.', 'suggested_roles' => ['FINANCE_OFFICER', 'CARRIER_ADMIN']],
        'clearing.manage' => ['description' => 'Create clearing batches, attach payments, record settlement (maker).', 'suggested_roles' => ['FINANCE_OFFICER']],
        'clearing.reconcile' => ['description' => 'Reconcile a settled clearing batch (checker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'cashier.sessions.view' => ['description' => 'Read cashier sessions and collections.', 'suggested_roles' => ['FINANCE_OFFICER', 'BRANCH_MANAGER', 'CASHIER', 'CARRIER_SUPER_ADMIN']],
        'cashier.sessions.operate' => ['description' => 'Open a cashier session, collect cash / cheques, close with a count.', 'suggested_roles' => ['FINANCE_OFFICER', 'CASHIER']],
        'cashier.sessions.approve' => ['description' => 'Approve or reject a closed cashier session (supervisor; never the cashier).', 'suggested_roles' => ['BRANCH_MANAGER', 'CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'fx.rates.view' => ['description' => 'Read FX rates.', 'suggested_roles' => ['FINANCE_OFFICER', 'CARRIER_ADMIN', 'REINSURANCE_OFFICER', 'BRANCH_MANAGER']],
        'fx.rates.manage' => ['description' => 'Record a new (immutable) FX rate.', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'statements.read' => ['description' => 'Read customer / broker / agent / carrier account statements.', 'suggested_roles' => ['FINANCE_OFFICER', 'CARRIER_ADMIN', 'BROKER_ADMIN']],
        'finance.exceptions.view' => ['description' => 'Read the finance exception centre.', 'suggested_roles' => ['FINANCE_OFFICER', 'CARRIER_ADMIN', 'FINANCE_MANAGER']],
        'finance.reports.view' => ['description' => 'Run the finance reports (JSON / CSV).', 'suggested_roles' => ['FINANCE_OFFICER', 'CARRIER_ADMIN', 'REINSURANCE_OFFICER', 'FINANCE_MANAGER']],
    ],

    // D10 owner decision — Batch 10 finance / technical accounting / commission statement / bordereaux permissions.
    'batch10_finance' => [
        'ledger.periods.close' => ['description' => 'Close an accounting period (soft/hard close).', 'suggested_roles' => ['FINANCE_OFFICER', 'FINANCE_MANAGER']],
        'ledger.periods.reopen' => ['description' => 'Reopen a closed accounting period (senior checker only).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'ledger.approve' => ['description' => 'Approve or reject a validated manual journal (checker; never the maker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'ledger.post' => ['description' => 'Post an approved manual journal to the ledger (checker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'technical_accounting.read' => ['description' => 'Read technical accounting reports, UPR runs and actuarial imports.', 'suggested_roles' => ['FINANCE_OFFICER', 'REINSURANCE_OFFICER', 'CARRIER_ADMIN', 'CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'technical_accounting.actuarial.import' => ['description' => 'Import actuarial reserve figures (maker).', 'suggested_roles' => ['FINANCE_OFFICER']],
        'technical_accounting.actuarial.approve' => ['description' => 'Approve an actuarial import (checker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'technical_accounting.upr.post' => ['description' => 'Post a UPR run to the ledger (checker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'commission.statements.adjust' => ['description' => 'Propose a commission statement adjustment (maker).', 'suggested_roles' => ['FINANCE_OFFICER']],
        'commission.statements.adjustments.approve' => ['description' => 'Approve a commission statement adjustment (checker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'commission.statements.dispute' => ['description' => 'Raise a dispute on a commission statement.', 'suggested_roles' => ['BROKER_ADMIN', 'FINANCE_OFFICER']],
        'commission.statements.dispute.resolve' => ['description' => 'Resolve a commission statement dispute (checker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'settlement.reconcile' => ['description' => 'Reconcile a settled settlement batch (checker).', 'suggested_roles' => ['CARRIER_SUPER_ADMIN', 'FINANCE_MANAGER']],
        'bordereaux.view' => ['description' => 'Read bordereaux and their lines.', 'suggested_roles' => ['BROKER_STAFF', 'BROKER_SUPERVISOR', 'BROKER_ADMIN', 'CARRIER_STAFF', 'CARRIER_ADMIN', 'CARRIER_SUPER_ADMIN', 'FINANCE_OFFICER', 'FINANCE_MANAGER']],
    ],

    // Agent CR — Batch 11/12 claims, legal, collections and SoD permissions (maker vs checker).
    'claims_batch11_12' => [
        'claims.parties.manage' => ['description' => 'Add, update or remove parties (claimant, witness, third party) on a claim (maker).', 'suggested_roles' => ['CLAIMS_OFFICER', 'CLAIMS_MANAGER']],
        'claims.coverage.check' => ['description' => 'Run a coverage check against the policy as at the loss date.', 'suggested_roles' => ['CLAIMS_OFFICER', 'CLAIMS_MANAGER', 'CARRIER_ADMIN', 'CARRIER_SUPER_ADMIN']],
        'claims.coverage.resolve' => ['description' => 'Resolve a coverage exception / referral (checker).', 'suggested_roles' => ['CLAIMS_MANAGER', 'CARRIER_SUPER_ADMIN']],
        'claims.experts.assign' => ['description' => 'Appoint a loss adjuster / expert on a claim.', 'suggested_roles' => ['CLAIMS_OFFICER', 'CLAIMS_MANAGER']],
        'claims.experts.review' => ['description' => 'Review and accept or reject an expert report (checker).', 'suggested_roles' => ['CLAIMS_MANAGER']],
        'claims.experts.work' => ['description' => 'Work an expert assignment: accept, visit, upload report (assigned adjuster).', 'suggested_roles' => ['ADJUSTER']],
        'claims.assessment.record' => ['description' => 'Record a loss assessment / quantum (maker).', 'suggested_roles' => ['CLAIMS_OFFICER', 'CLAIMS_MANAGER', 'ADJUSTER']],
        'claims.assessment.review' => ['description' => 'Review and approve a loss assessment (checker).', 'suggested_roles' => ['CLAIMS_MANAGER']],
        'claims.investigation.manage' => ['description' => 'Open and work a claim investigation (maker).', 'suggested_roles' => ['CLAIMS_OFFICER', 'CLAIMS_MANAGER']],
        'claims.investigation.conclude' => ['description' => 'Conclude an investigation with an outcome (checker).', 'suggested_roles' => ['CLAIMS_MANAGER']],
        'claims.carrier.manual_entry' => ['description' => 'Manually key a carrier decision / message when the carrier exchange is unavailable (maker).', 'suggested_roles' => ['CLAIMS_OFFICER', 'CLAIMS_MANAGER', 'CARRIER_STAFF', 'CARRIER_ADMIN']],
        'claims.carrier.manual_approve' => ['description' => 'Approve a manually keyed carrier entry (checker).', 'suggested_roles' => ['CLAIMS_MANAGER', 'CARRIER_SUPER_ADMIN']],
        'claims.carrier.keys' => ['description' => 'Manage carrier exchange signing/API keys for claims.', 'suggested_roles' => ['CLAIMS_MANAGER', 'CARRIER_SUPER_ADMIN']],
        'claims.decision.appeal' => ['description' => 'Lodge an appeal against a claim decision on behalf of the claimant.', 'suggested_roles' => ['CLAIMS_OFFICER', 'CLAIMS_MANAGER', 'CUSTOMER_SERVICE']],
        'claims.decision.supervise' => ['description' => 'Supervise / override claim decisions and decide appeals (checker).', 'suggested_roles' => ['CLAIMS_MANAGER']],
        'claims.settlement.calculate' => ['description' => 'Calculate a claim settlement (maker).', 'suggested_roles' => ['CLAIMS_OFFICER', 'CLAIMS_MANAGER']],
        'claims.settlement.offer' => ['description' => 'Offer a calculated settlement to the claimant (maker).', 'suggested_roles' => ['CLAIMS_OFFICER', 'CLAIMS_MANAGER']],
        'claims.settlement.respond' => ['description' => 'Record the claimant\'s acceptance or dispute of a settlement offer (back-office route, not customer-facing).', 'suggested_roles' => ['CLAIMS_OFFICER', 'CLAIMS_MANAGER']],
        'claims.settlement.discharge' => ['description' => 'Request and confirm the discharge / release form.', 'suggested_roles' => ['CLAIMS_OFFICER', 'CLAIMS_MANAGER']],
        'claims.settlement.pay' => ['description' => 'Request payment of an accepted, discharged settlement (checker).', 'suggested_roles' => ['CLAIMS_MANAGER']],
        'claims.close' => ['description' => 'Close a claim once the closure checklist passes.', 'suggested_roles' => ['CLAIMS_OFFICER', 'CLAIMS_MANAGER']],
        'claims.reopen.request' => ['description' => 'Request that a closed claim be reopened (maker).', 'suggested_roles' => ['CLAIMS_OFFICER', 'CLAIMS_MANAGER']],
        'claims.reopen.approve' => ['description' => 'Approve reopening a closed claim (checker).', 'suggested_roles' => ['CLAIMS_MANAGER']],
        'legal.matters.view' => ['description' => 'Read legal / litigation matters linked to claims.', 'suggested_roles' => ['CLAIMS_OFFICER', 'CLAIMS_MANAGER', 'COMPLIANCE_ADMIN']],
        'legal.matters.manage' => ['description' => 'Open and manage legal / litigation matters.', 'suggested_roles' => ['CLAIMS_MANAGER', 'COMPLIANCE_ADMIN']],
        'collections.view' => ['description' => 'Read collections (recoveries, overdue receivables) cases.', 'suggested_roles' => ['FINANCE_OFFICER', 'FINANCE_MANAGER', 'CLAIMS_OFFICER', 'CLAIMS_MANAGER']],
        'collections.manage' => ['description' => 'Work collections cases: contact, promise to pay, propose write-off (maker).', 'suggested_roles' => ['FINANCE_OFFICER']],
        'collections.write_off.approve' => ['description' => 'Approve a collections write-off (checker).', 'suggested_roles' => ['FINANCE_MANAGER', 'CARRIER_SUPER_ADMIN']],
        'fraud.sod.report' => ['description' => 'Read the segregation-of-duties (maker = checker) exception report.', 'suggested_roles' => ['COMPLIANCE_ADMIN']],
    ],

    'business_data' => [
        'modules' => [
            'customers', 'policies', 'risk_assets', 'claims', 'carrier', 'broker', 'agent', 'provider', 'payout', 'settlement',
            'commission', 'ledger', 'reconciliation', 'refund', 'statements', 'bordereaux', 'underwriting', 'quotes', 'proposals',
            'parties', 'partners', 'partner', 'payments', 'renewals', 'documents', 'fraud', 'stickers', 'privacy', 'reports',
            'regulator', 'fulfilment', 'fulfilments', 'support', 'cases', 'distribution', 'kyc', 'crm', 'beneficiaries', 'rating',
            'finance', 'premium_status', 'premium_components', 'clearing', 'cashier', 'technical_accounting', 'legal', 'collections',
        ],
        'permissions' => ['trust.dsr.receive', 'trust.dsr.verify', 'trust.dsr.resolve', 'attribution.transfer'],
        'platform_exceptions' => ['documents.templates.manage', 'cases.calendar.manage', 'cases.admin'],
    ],

    'never_grant_to' => ['CUSTOMER', 'AGENT', 'BROKER_STAFF'],
];
