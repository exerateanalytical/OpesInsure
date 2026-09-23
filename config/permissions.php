<?php

/**
 * Central catalogue of sensitive permission strings introduced by the Wave 9 / Wave 11
 * authorization hotfix, plus the Wave 12 'agent' category below. This is documentation +
 * a single source of truth for tests — `RequirePermission` and `User::hasPermission()`
 * still read permissions from each `roles.permissions` jsonb array at runtime, this file
 * does not grant anything by itself.
 *
 * SYSTEM_ADMIN bypasses all permission checks (see User::hasPermission()).
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
            'description' => 'Replay an allowlisted queued offline operation (SyncController::dispatch — see SyncOperationDispatchService::ALLOWLIST).',
            'suggested_roles' => ['AGENT'],
        ],
    ],

    'never_grant_to' => ['CUSTOMER', 'AGENT', 'BROKER_STAFF'],
];
