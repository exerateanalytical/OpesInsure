<?php

/*
 * S12 — monitoring & alerting without external paid services (App\Application\Operations\Monitoring).
 * Every threshold is an operator setting; the defaults are launch values.
 */
$list = static fn (string $key): array => array_values(array_filter(array_map('trim', explode(',', (string) env($key, '')))));

return [
    // Release id shown in the admin footer, stamped on error events and returned by /status. APP_RELEASE_ID wins;
    // otherwise deploy.sh's release directory name (releases/rYYYYMMDD-HHMMSS) or a RELEASE file at the project root.
    'release' => env('APP_RELEASE_ID'),

    // /status for uptime monitors: "Authorization: Bearer <token>" (or ?token=) — unset = only an authenticated
    // platform administrator (auth:api) can read it.
    'status_token' => env('MONITORING_STATUS_TOKEN'),

    'errors' => [
        'enabled' => (bool) env('MONITORING_ERRORS_ENABLED', true),
    ],

    'alerts' => [
        'permission' => 'operations.alerts.receive',
        // A firing alert is re-sent at most once per cooldown while it keeps firing.
        'cooldown_minutes' => (int) env('MONITORING_ALERT_COOLDOWN_MINUTES', 60),
        // Used when no recipient can be read from the database (e.g. the database itself is down).
        'fallback_emails' => $list('MONITORING_ALERT_EMAILS'),
        'fallback_phones' => $list('MONITORING_ALERT_PHONES'),
        'sms' => (bool) env('MONITORING_ALERT_SMS', true),
        // Look-back window of the rate checks.
        'window_minutes' => (int) env('MONITORING_ALERT_WINDOW_MINUTES', 15),
        'thresholds' => [
            'error_spike' => (int) env('MONITORING_ERROR_SPIKE', 25),               // unhandled errors in the last 5 min
            'failed_jobs' => (int) env('MONITORING_FAILED_JOBS', 5),                // new failed jobs in the window
            'queue_backlog' => (int) env('MONITORING_QUEUE_BACKLOG', 500),          // pending jobs
            'queue_age_minutes' => (int) env('MONITORING_QUEUE_AGE_MINUTES', 15),   // oldest pending job
            'payment_auth_failures' => (int) env('MONITORING_PAYMENT_AUTH_FAILURES', 3),
            'carrier_min_calls' => (int) env('MONITORING_CARRIER_MIN_CALLS', 10),
            'carrier_failure_rate' => (float) env('MONITORING_CARRIER_FAILURE_RATE', 0.5),
            'scanner_backlog' => (int) env('MONITORING_SCANNER_BACKLOG', 10),
            'scanner_age_minutes' => (int) env('MONITORING_SCANNER_AGE_MINUTES', 60),
            'disk_free_percent' => (float) env('MONITORING_DISK_FREE_PERCENT', 10),
            'db_connection_errors' => (int) env('MONITORING_DB_CONNECTION_ERRORS', 3),
        ],
    ],
];
