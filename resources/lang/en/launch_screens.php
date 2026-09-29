<?php

// Launch 2026-10-02 (agent P10): read-only staff screens built from the screen-register gap list
// (docs/LAUNCH_SCREEN_GAPS_2026-09-29.md). French: resources/lang/fr/launch_screens.php.
return [
    'nav' => [
        'system_health' => 'System health',
        'backup_recovery' => 'Backup & recovery',
        'finance_exceptions' => 'Finance exceptions',
        'account_statements' => 'Account statements',
        'audit_trail' => 'Audit trail',
        'login_activity' => 'Login activity',
    ],
    'intro' => [
        'system_health' => 'Live checks of the database, queue, scheduler, storage, e-mail and SMS.',
        'backup_recovery' => 'Backup restore exercises, newest first. Targets: RPO :rpo min, RTO :rto min.',
        'finance_exceptions' => 'Open finance exceptions for this organisation, by source.',
        'account_statements' => 'Choose a customer, broker, agent or carrier and a period to build its account statement.',
        'audit_trail' => 'The 200 most recent audit entries for this organisation.',
        'login_activity' => 'Recent sign-ins by members of this organisation. IP addresses are never shown.',
    ],
    'columns' => [
        'check' => 'Check', 'status' => 'Status', 'details' => 'Details', 'source' => 'Source', 'count' => 'Open items',
        'amounts' => 'Amounts', 'breakdown' => 'Breakdown', 'available' => 'Available', 'sequence' => 'No.',
        'occurred_at' => 'Date', 'action' => 'Action', 'subject_type' => 'Record type', 'subject_id' => 'Record',
        'actor_id' => 'User', 'correlation_id' => 'Correlation', 'user' => 'User', 'method' => 'Method', 'device' => 'Device',
        'platform' => 'Platform', 'country' => 'Country', 'new_device' => 'New device', 'anomaly_flags' => 'Warnings',
        'environment' => 'Environment', 'rto' => 'RTO (actual / target, min)', 'rpo' => 'RPO (actual / target, min)',
        'subject' => 'Account holder', 'from' => 'From', 'to' => 'To', 'currency' => 'Currency', 'line_type' => 'Type',
        'description' => 'Description', 'reference' => 'Reference', 'amount' => 'Amount', 'balance' => 'Balance',
    ],
    'checks' => [
        'database' => 'Database', 'queue' => 'Queue', 'scheduler' => 'Scheduler', 'storage' => 'File storage', 'mail' => 'E-mail', 'sms' => 'SMS',
        'cache' => 'Cache',
    ],
    'sources' => [
        'issuance_exceptions' => 'Issuance exceptions',
        'reconciliation_exceptions' => 'Reconciliation exceptions',
        'refunds_awaiting_action' => 'Refunds awaiting action',
        'clearing_variances' => 'Clearing variances',
        'overdue_obligations' => 'Overdue obligations',
        'cashier_sessions_awaiting_approval' => 'Cashier sessions awaiting approval',
    ],
    'subjects' => ['customer' => 'Customer', 'broker' => 'Broker', 'agent' => 'Agent', 'carrier' => 'Insurer'],
    'actions' => ['build_statement' => 'Build statement'],
    'statement_summary' => ':number — :name, :from to :to. Opening balance :opening, closing balance :closing.',
    'statement_empty' => 'No statement yet. Use "Build statement" above.',
    'empty' => 'Nothing to show.',
    'yes' => 'Yes',
    'no' => 'No',
];
