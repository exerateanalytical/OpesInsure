<?php

declare(strict_types=1);

/*
 | Agent B7 — security centre (REQ-SEC-001 / 003 / 005, REQ-MOB-007 backend).
 | Every external integration here is OFF unless explicitly configured; an
 | unconfigured attestation verifier yields UNVERIFIED, never PASS.
 */
return [
    'login' => [
        // Request headers carrying edge-geolocation (e.g. set by a CDN/WAF).
        // Null = no geo data: the impossible-travel check is then skipped.
        'geo_headers' => [
            'latitude' => env('SECURITY_GEO_LAT_HEADER'),
            'longitude' => env('SECURITY_GEO_LNG_HEADER'),
            'country' => env('SECURITY_GEO_COUNTRY_HEADER'),
        ],
        // PLATFORM_PROVISIONAL threshold: travel faster than this between two
        // geolocated logins is flagged as impossible travel.
        'impossible_travel_kmh' => (int) env('SECURITY_IMPOSSIBLE_TRAVEL_KMH', 900),
    ],

    'privileged_access' => [
        // Retired (REQ-SEC-001): the one-step "caller is the approver" grant.
        // Leave false; true only as an emergency rollback switch.
        'legacy_single_step_grant' => (bool) env('PRIVILEGED_ACCESS_LEGACY_SINGLE_STEP', false),
        // PLATFORM_PROVISIONAL: longest allowed privileged-access window.
        'max_window_hours' => (int) env('PRIVILEGED_ACCESS_MAX_WINDOW_HOURS', 72),
    ],

    'attestation' => [
        'play_integrity' => [
            'enabled' => (bool) env('PLAY_INTEGRITY_ENABLED', false),
            'package_name' => env('PLAY_INTEGRITY_PACKAGE_NAME'),
            // Short-lived OAuth access token for the Play Integrity API. Minting
            // it from a service account is an operations concern (deploy note).
            'access_token' => env('PLAY_INTEGRITY_ACCESS_TOKEN'),
            'endpoint' => env('PLAY_INTEGRITY_ENDPOINT', 'https://playintegrity.googleapis.com/v1'),
        ],
        'app_attest' => [
            'enabled' => (bool) env('APP_ATTEST_ENABLED', false),
            'team_id' => env('APP_ATTEST_TEAM_ID'),
            'bundle_id' => env('APP_ATTEST_BUNDLE_ID'),
        ],
    ],

    // Served at /.well-known/assetlinks.json and /.well-known/apple-app-site-association.
    'app_links' => [
        'android' => [
            'package_name' => env('APP_LINKS_ANDROID_PACKAGE'),
            // Comma-separated SHA-256 signing-certificate fingerprints.
            'sha256_cert_fingerprints' => array_values(array_filter(array_map('trim', explode(',', (string) env('APP_LINKS_ANDROID_SHA256', ''))))),
        ],
        'ios' => [
            // "<TEAMID>.<bundle id>", comma-separated.
            'app_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('APP_LINKS_IOS_APP_IDS', ''))))),
            'paths' => array_values(array_filter(array_map('trim', explode(',', (string) env('APP_LINKS_IOS_PATHS', '/app/*'))))),
        ],
    ],

    'crash_reports' => [
        'max_stack_bytes' => 8000,
        'max_message_bytes' => 500,
    ],
];
