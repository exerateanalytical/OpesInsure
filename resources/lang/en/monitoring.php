<?php

return [
    'footer' => [
        'release' => 'OpesInsure release :release',
    ],
    'errors' => [
        'nav' => 'Errors',
        'title' => 'Errors',
        'subtitle' => 'Unhandled exceptions grouped by fingerprint. No request bodies or personal data are stored. A resolved error that happens again is reopened.',
        'empty' => 'No errors recorded.',
        'columns' => [
            'status' => 'Status',
            'exception' => 'Exception',
            'occurrences' => 'Count',
            'route' => 'Route',
            'location' => 'Location',
            'first_seen' => 'First seen',
            'last_seen' => 'Last seen',
            'release' => 'Release',
        ],
        'status' => [
            'OPEN' => 'Open',
            'RESOLVED' => 'Resolved',
            'IGNORED' => 'Ignored',
        ],
        'actions' => [
            'errorResolve' => 'Resolve',
            'errorIgnore' => 'Ignore',
            'errorReopen' => 'Reopen',
            'done' => 'Error updated.',
        ],
    ],
    'alert_mail' => [
        'subject_firing' => '[:severity] :title — :host',
        'subject_resolved' => '[RESOLVED] :title — :host',
        'footer' => 'Release :release on :host at :time. You receive this because you hold operations.alerts.receive.',
    ],
    'alerts' => [
        'database' => ['title' => 'Database connection errors', 'body' => 'Database reachable: :reachable. Connection errors in the last :minutes min: :errors.'],
        'error_spike' => ['title' => 'Error spike', 'body' => ':count unhandled errors in the last 5 minutes (threshold :threshold). See Operations → Errors.'],
        'failed_jobs' => ['title' => 'Failed jobs growing', 'body' => ':count jobs failed in the last :minutes min. See Operations → Failed jobs.'],
        'queue_backlog' => ['title' => 'Queue backlog', 'body' => ':count jobs waiting; the oldest has waited :age min. Check the queue worker.'],
        'scheduler_heartbeat' => ['title' => 'Scheduler heartbeat missing', 'body' => 'Last scheduler heartbeat: :age min ago. Scheduled tasks (payments polling, renewals, alerts) may not be running.'],
        'payment_auth_failures' => ['title' => 'Payment provider authentication failures', 'body' => ':count authentication failures in the last :minutes min (:providers). Check the provider credentials.'],
        'carrier_api_failures' => ['title' => 'Carrier API failure rate', 'body' => 'Carrier API calls failing in the last :minutes min: :carriers (worst :rate%).'],
        'scanner_backlog' => ['title' => 'Malware scanner unavailable backlog', 'body' => ':count uploads held because the scanner is unavailable; the oldest for :age min.'],
        'disk_space' => ['title' => 'Low disk space', 'body' => 'Only :percent% free (:free_gb GB) on the storage disk.'],
    ],
];
