<?php

// Admin / insurer / broker home dashboard widgets and the shared Reports screen (web).
return [
    'widgets' => [
        'premium_collected' => 'Premium collected (last 6 months)',
        'my_work' => 'My work queue',
        'expiring_policies' => 'Policies expiring within 30 days',
        'open_claims' => 'Open claims',
        'recent_activity' => 'Recent activity',
    ],
    'columns' => [
        'policy_number' => 'Policy', 'status' => 'Status', 'coverage_ends_at' => 'Ends', 'premium' => 'Premium', 'currency' => 'Currency',
        'claim_number' => 'Claim', 'reserve' => 'Reserve', 'created_at' => 'Reported', 'title' => 'Item', 'kind' => 'Type', 'due_at' => 'Due',
        'action' => 'Action', 'subject' => 'Record', 'actor' => 'By', 'when' => 'When',
    ],
    'metrics' => [
        'premium_collected_30d' => 'Premium collected (30 days)', 'receivables_outstanding' => 'Receivables outstanding',
        'receivables_overdue' => 'Receivables overdue', 'commissions_pending' => 'Commissions pending', 'refunds_open' => 'Refunds to action',
    ],
    'states' => [
        'empty' => 'Nothing to show.',
        'error' => 'This information could not be loaded. Try again later.',
        'loading' => 'Loading…',
    ],
    'reports' => [
        'title' => 'Reports',
        'report' => 'Report',
        'from' => 'From',
        'to' => 'To',
        'currency' => 'Currency',
        'as_of' => 'As of',
        'export_csv' => 'Export CSV',
        'insurance_portfolio' => 'Insurance portfolio summary',
        'renewals' => 'Renewal pipeline by status',
        'not_available' => 'not available',
        'kpi' => 'KPI records',
        'rows' => '{1} :count row|[2,*] :count rows',
        'truncated' => 'the list is truncated; narrow the period',
    ],
];
