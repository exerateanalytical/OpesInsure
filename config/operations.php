<?php

/*
 * Agent B6 — REQ-OPS-001/002/004/005 operations console configuration.
 * Every threshold/target here is an operator setting. RPO/RTO targets have NO default: until the owner sets them,
 * restore verifications are recorded without a target and the report says "target not configured".
 */
$optionalInt = static fn (string $key): ?int => ($v = env($key)) === null || $v === '' ? null : (int) $v;

return [
    'scheduler' => [
        // The scheduler writes a heartbeat every minute (ops:heartbeat). Older than this = stale.
        'heartbeat_stale_seconds' => (int) env('OPS_HEARTBEAT_STALE_SECONDS', 180),
    ],

    'queues' => [
        // Queues whose pending depth the console reports.
        'monitored' => array_values(array_filter(explode(',', (string) env('OPS_MONITORED_QUEUES', 'default')))),
    ],

    'dr' => [
        'environment' => env('OPS_DR_ENVIRONMENT', env('APP_ENV', 'production')),
        'target_rpo_minutes' => $optionalInt('OPS_DR_TARGET_RPO_MINUTES'),
        'target_rto_minutes' => $optionalInt('OPS_DR_TARGET_RTO_MINUTES'),
        'restore' => [
            // Directory holding the backups; the newest file matching `pattern` is restored.
            'backup_path' => env('OPS_DR_BACKUP_PATH'),
            'pattern' => env('OPS_DR_BACKUP_PATTERN', '*.{dump,sql,backup}'),
            // A database connection (config/database.php) pointing at a SCRATCH database. Never the primary.
            'scratch_connection' => env('OPS_DR_SCRATCH_CONNECTION'),
            // Shell template. Placeholders: {file} {host} {port} {database} {username}. The password is passed as PGPASSWORD.
            'restore_command' => env('OPS_DR_RESTORE_COMMAND', 'pg_restore --clean --if-exists --no-owner --no-privileges -h {host} -p {port} -U {username} -d {database} {file}'),
            'timeout_seconds' => (int) env('OPS_DR_RESTORE_TIMEOUT', 3600),
            // Tables compared source vs restored copy, on rows created at or before the backup time.
            // key = column hashed (ordered) into the checksum; created = cut-off column (null = whole table).
            'tables' => [
                'audit_log' => ['key' => 'entry_hash', 'created' => 'created_at'],
                'journals' => ['key' => 'id', 'created' => 'created_at'],
                'journal_lines' => ['key' => 'id', 'created' => 'created_at'],
                'policies' => ['key' => 'id', 'created' => 'created_at'],
                'payment_intents' => ['key' => 'id', 'created' => 'created_at'],
                'claims' => ['key' => 'id', 'created' => 'created_at'],
            ],
        ],
    ],

    'readiness' => [
        'report_path' => 'docs/audit/PRODUCTION_READINESS.md',
        // A PASSED restore verification newer than this counts as DR evidence (criterion 16).
        'restore_evidence_max_age_days' => (int) env('OPS_READINESS_RESTORE_MAX_AGE_DAYS', 90),
    ],
];
