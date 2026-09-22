<?php

/**
 * Central catalogue of sensitive permission strings introduced by the Wave 9 / Wave 11
 * authorization hotfix. This is documentation + a single source of truth for tests —
 * `RequirePermission` and `User::hasPermission()` still read permissions from each
 * `roles.permissions` jsonb array at runtime, this file does not grant anything by itself.
 *
 * SYSTEM_ADMIN bypasses all permission checks (see User::hasPermission()).
 * The local demo seeder (database/seeders/DatabaseSeeder.php) grants every demo account
 * the wildcard '*' permission for exploration purposes; real tenant role provisioning
 * must assign these individually and must NOT grant them to customer, agent or
 * broker-staff roles.
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

    'never_grant_to' => ['CUSTOMER', 'AGENT', 'BROKER_STAFF'],
];
