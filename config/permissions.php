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

    'provider' => [
        'provider.portal.read' => ['description' => 'Open the provider portal (FRP V).', 'suggested_roles' => \App\Application\Identity\RoleCatalogue::PROVIDER_ROLES],
        'provider.eligibility.check' => ['description' => 'Check member eligibility / cover at the point of care.', 'suggested_roles' => ['PROVIDER_FRONT_DESK', 'PROVIDER_DOCTOR', 'PROVIDER_PHARMACY', 'PROVIDER_LAB']],
        'provider.claims.submit' => ['description' => 'Submit a provider (cashless) claim.', 'suggested_roles' => ['PROVIDER_ADMIN', 'PROVIDER_DOCTOR', 'PROVIDER_BILLING', 'PROVIDER_PHARMACY', 'PROVIDER_LAB']],
        'provider.claims.read' => ['description' => 'Read the provider organisation\'s claims.', 'suggested_roles' => ['PROVIDER_ADMIN', 'PROVIDER_BILLING', 'PROVIDER_FINANCE']],
        'provider.finance.read' => ['description' => 'Read provider settlements/remittances.', 'suggested_roles' => ['PROVIDER_ADMIN', 'PROVIDER_FINANCE']],
        'provider.staff.manage' => ['description' => 'Manage provider organisation users.', 'suggested_roles' => ['PROVIDER_ADMIN']],
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
    'business_data' => [
        'modules' => [
            'customers', 'policies', 'risk_assets', 'claims', 'carrier', 'broker', 'agent', 'provider', 'payout', 'settlement',
            'commission', 'ledger', 'reconciliation', 'refund', 'statements', 'bordereaux', 'underwriting', 'quotes', 'proposals',
            'parties', 'partners', 'partner', 'payments', 'renewals', 'documents', 'fraud', 'stickers', 'privacy', 'reports',
            'regulator', 'fulfilment', 'fulfilments', 'support', 'cases',
        ],
        'permissions' => ['trust.dsr.receive', 'trust.dsr.verify', 'trust.dsr.resolve'],
        'platform_exceptions' => ['documents.templates.manage', 'cases.calendar.manage', 'cases.admin'],
    ],

    'never_grant_to' => ['CUSTOMER', 'AGENT', 'BROKER_STAFF'],
];
