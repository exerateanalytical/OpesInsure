<?php

declare(strict_types=1);

/*
 * Activa Assurances Cameroun — carrier API connector (docs/integrations/activa/operations_2026-09-29.json).
 *
 * No secret lives here. The subscription key and the per-service logins are entered in the admin screen
 * (Integrations → Activa Assurances) and stored encrypted in carrier_api_connections. Until they are present the
 * connector reports CONFIG_REQUIRED and nothing is sent.
 */
return [
    'provider' => 'ACTIVA_CM',

    // Carrier the connector serves by default (carriers.insurer_code from the DGTCFM register seed).
    'insurer_code' => env('ACTIVA_INSURER_CODE', 'ACTIVA'),

    // Azure API Management gateway: https://<host>/<api path>/<operation>.
    'gateway_url' => env('ACTIVA_GATEWAY_URL', 'https://activaapimanagement.azure-api.net'),

    // API path of each service per environment. The SANDBOX paths are the ones Activa published (2026-09-29).
    // PRODUCTION paths follow Activa's naming without the "-test" suffix; confirm with Activa at go-live and
    // override per environment here or per connection (settings.paths) without a code change.
    'paths' => [
        'SANDBOX' => [
            'travel' => env('ACTIVA_SANDBOX_PATH_TRAVEL', 'cmr-travel'),
            'pricing' => env('ACTIVA_SANDBOX_PATH_PRICING', 'tarifiktor-cmr-test'),
            'subscription' => env('ACTIVA_SANDBOX_PATH_SUBSCRIPTION', 'souscription-cmr-test'),
            'documents' => env('ACTIVA_SANDBOX_PATH_DOCUMENTS', 'docgenerator-cmr-test'),
        ],
        'PRODUCTION' => [
            'travel' => env('ACTIVA_PRODUCTION_PATH_TRAVEL', 'cmr-travel'),
            'pricing' => env('ACTIVA_PRODUCTION_PATH_PRICING', 'tarifiktor-cmr'),
            'subscription' => env('ACTIVA_PRODUCTION_PATH_SUBSCRIPTION', 'souscription-cmr'),
            'documents' => env('ACTIVA_PRODUCTION_PATH_DOCUMENTS', 'docgenerator-cmr'),
        ],
    ],

    // {version} segment of the souscription / tarifiktor routes (/api/v{version}/…).
    'api_version' => env('ACTIVA_API_VERSION', '1'),

    'http' => [
        'connect_timeout' => (int) env('ACTIVA_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('ACTIVA_TIMEOUT', 30),
        // Retries apply to connection errors, timeouts and 5xx only; never to 4xx.
        'retries' => (int) env('ACTIVA_RETRIES', 3),
        'retry_base_ms' => (int) env('ACTIVA_RETRY_BASE_MS', 250),
    ],

    'token' => [
        // Refresh this many seconds before the token's announced expiry.
        'refresh_skew_seconds' => 120,
        // Used when an auth response carries no expiry (tarifiktor / souscription / docgenerator examples are empty).
        'default_ttl_seconds' => 1800,
    ],

    'circuit_breaker' => [
        'failure_threshold' => (int) env('ACTIVA_CB_THRESHOLD', 5),
        'window_seconds' => 120,
        'open_seconds' => (int) env('ACTIVA_CB_OPEN_SECONDS', 60),
    ],

    'sync' => [
        'max_attempts' => 8,
        'base_backoff_seconds' => 120,
        // Policies issued before this many days ago are not back-filled automatically.
        'backfill_days' => 30,
    ],

    // Our line codes → Activa product family (which SouscriptionCMR / RenouvellementCMR operation).
    'lines' => [
        'TRAVEL' => 'TRAVEL',
        'MOTOR' => 'AUTO', 'AUTO' => 'AUTO',
        'HOME' => 'MRH', 'MRH' => 'MRH', 'PROPERTY' => 'MRH',
        'HEALTH' => 'SANTE',
        'PERSONAL_ACCIDENT' => 'IA', 'ACCIDENT' => 'IA', 'PA' => 'IA',
    ],

    // Activa category code (codecate) per family. Activa has not published its category table yet: when unset, the
    // connection's settings.categories or the quote's risk_facts.activa.codecate must provide it, otherwise the sync
    // stops with MAPPING_REQUIRED (never a guessed code).
    'categories' => [
        'AUTO' => env('ACTIVA_CODECATE_AUTO'),
        'MRH' => env('ACTIVA_CODECATE_MRH'),
        'SANTE' => env('ACTIVA_CODECATE_SANTE'),
        'IA' => env('ACTIVA_CODECATE_IA'),
    ],

    // Canonical Document Register type under which Activa's PDF is stored as the carrier original.
    'document_types' => [
        'TRAVEL' => 'TRAVEL_INSURANCE_CERTIFICATE',
        'AUTO' => 'MOTOR_INSURANCE_CERTIFICATE',
        'MRH' => 'CERTIFICATE_OF_INSURANCE',
        'SANTE' => 'CERTIFICATE_OF_INSURANCE',
        'IA' => 'CERTIFICATE_OF_INSURANCE',
        'CONTRACT' => 'POLICY_SCHEDULE',
    ],

    // Travel defaults (POST /travel/quotes_requests context).
    'travel' => [
        'currency' => 'Franc CFA',
        'country' => 'Cameroun',
        'language' => 'fr',
        'category' => 'Offre_Standard_Voyage',
        'subscription_country' => 'CM',
    ],

    // Reference data domain (key of the ReferentialData payload) → master_data list code it maps onto.
    // Values are linked in carrier_master_data_mappings only on an exact code/label/alias match; the rest stays
    // UNMAPPED in carrier_reference_data for review. Extend once Activa's payload shape is confirmed.
    'reference_domains' => [
        'marques' => 'brand',
        'usages' => 'usage_type',
        'energies' => 'fuel_type',
        'villes' => 'city',
        'professions' => 'occupation',
    ],
];
