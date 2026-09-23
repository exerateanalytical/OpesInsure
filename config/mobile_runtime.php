<?php

declare(strict_types=1);

/**
 * Server-authoritative source of truth for GET /mobile/runtime/bootstrap,
 * step-up authentication and POST /mobile/runtime/telemetry (see
 * App\Application\Runtime\MobileRuntimeService,
 * App\Application\Security\MobileStepUpService and
 * App\Application\Runtime\MobileTelemetryService).
 *
 * "Laravel remains the authority" (per the Expo patch-7 merge guide): the
 * client never chooses the minimum version, maintenance outcome, service
 * status or device-risk decision — it only ever reads these values back.
 * There is deliberately no admin UI/DB-backed settings store for these
 * values in this batch (see the batch report's "remaining gaps" section);
 * ops updates them via env vars and a redeploy, the same way payments.php's
 * provider credentials are managed. A future batch can promote this to a
 * Filament-editable settings table without changing the response contract.
 */
return [
    'release' => [
        // Semver strings compared with PHP's native version_compare().
        'minimum_version' => env('MOBILE_MIN_VERSION', '1.0.0'),
        'latest_version' => env('MOBILE_LATEST_VERSION', '1.0.0'),
        'store_url' => env('MOBILE_STORE_URL'),
    ],

    'maintenance' => [
        'active' => (bool) env('MOBILE_MAINTENANCE_ACTIVE', false),
        'message' => env('MOBILE_MAINTENANCE_MESSAGE'),
        'ends_at' => env('MOBILE_MAINTENANCE_ENDS_AT'), // ISO-8601 string or null
    ],

    // Keys match the demo fixture shipped in patch 7
    // (src/data/demo/production-readiness.v1.json) exactly, so a real
    // backend response and the app's own demo-mode response have the same
    // shape. Values: OPERATIONAL | DEGRADED | UNAVAILABLE.
    'services' => [
        'LARAVEL_API' => env('MOBILE_SERVICE_STATUS_API', 'OPERATIONAL'),
        'MTN_MOMO' => env('MOBILE_SERVICE_STATUS_MTN_MOMO', 'OPERATIONAL'),
        'ORANGE_MONEY' => env('MOBILE_SERVICE_STATUS_ORANGE_MONEY', 'OPERATIONAL'),
        'CARRIER_GATEWAY' => env('MOBILE_SERVICE_STATUS_CARRIER_GATEWAY', 'OPERATIONAL'),
        'SMS' => env('MOBILE_SERVICE_STATUS_SMS', 'OPERATIONAL'),
    ],

    // Static default until a real device-attestation pipeline exists (see
    // Patch 8's device-attestation contract — out of this batch's scope,
    // documented as a gap). ALLOW | LIMIT | BLOCK.
    'device_risk_action' => env('MOBILE_DEVICE_RISK_ACTION', 'ALLOW'),

    'step_up' => [
        'grant_ttl_seconds' => (int) env('MOBILE_STEP_UP_GRANT_TTL_SECONDS', 300),
        'otp_ttl_seconds' => (int) env('MOBILE_STEP_UP_OTP_TTL_SECONDS', 300),
        'max_attempts' => (int) env('MOBILE_STEP_UP_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => (int) env('MOBILE_STEP_UP_RESEND_COOLDOWN_SECONDS', 60),
        // Retroactively required by PAYMENT_REFUND_REQUEST (wired onto
        // MobilePaymentService::requestRefund() in this batch),
        // COMMISSION_WITHDRAWAL and CLAIM_SETTLEMENT_DECISION (both to be
        // adopted by their owning batches once those endpoints exist — see
        // the batch report for the exact middleware to add).
        'purposes' => ['PAYMENT_REFUND_REQUEST', 'COMMISSION_WITHDRAWAL', 'CLAIM_SETTLEMENT_DECISION'],
    ],

    'telemetry' => [
        'retention_days' => (int) env('MOBILE_TELEMETRY_RETENTION_DAYS', 30),
        // Deliberately small and business-shaped, never free-text-derived —
        // this is the enforcement mechanism for "reject arbitrary nested
        // payloads / never accept PII".
        'events' => [
            'APP_LAUNCHED', 'APP_CRASHED', 'APP_FOREGROUNDED', 'APP_BACKGROUNDED',
            'SCREEN_VIEWED', 'API_ERROR', 'NETWORK_TIMEOUT', 'JS_EXCEPTION',
            'PAYMENT_FAILED', 'OFFLINE_SYNC_FAILED', 'STEP_UP_CHALLENGE_FAILED',
            'FORCE_UPDATE_SHOWN', 'MAINTENANCE_SHOWN', 'DEVICE_RISK_LIMITED',
        ],
        // Attribute values must additionally be scalar (string/int/float/bool)
        // and short — see MobileTelemetryService::sanitizeAttributes().
        'attributes' => [
            'screen', 'error_code', 'http_status', 'duration_ms', 'retry_count',
            'network_type', 'locale', 'platform', 'reason',
        ],
    ],
];
