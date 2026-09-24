<?php

return [
    // Public verification page the certificate QR code points at.
    'verify_url' => env('POLICY_VERIFY_URL', 'https://insurance.opesdatacenter.tech/verify'),

    // Disk issuance PDFs are written to (local by design: documents are
    // served through signed, short-lived URLs, never a public bucket).
    'documents_disk' => env('POLICY_DOCUMENTS_DISK', 'local'),
    'download_ttl_minutes' => (int) env('POLICY_DOCUMENT_URL_TTL', 30),

    // policies:notify-expiry reminder offsets, in days before coverage ends.
    'expiry_reminder_days' => [30, 14, 7, 1],
    // policies:expire — ACTIVE -> EXPIRING this many days before the end.
    'expiring_window_days' => (int) env('POLICY_EXPIRING_WINDOW_DAYS', 30),
    // EXPIRED -> LAPSED once this many days have passed after the end
    // without a renewal.
    'grace_period_days' => (int) env('POLICY_GRACE_PERIOD_DAYS', 15),

    'push' => [
        'expo_url' => env('EXPO_PUSH_URL', 'https://exp.host/--/api/v2/push/send'),
        'expo_access_token' => env('EXPO_ACCESS_TOKEN'),
    ],
];
