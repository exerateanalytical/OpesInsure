<?php

declare(strict_types=1);

/*
 * REQ-HLT-002 health preauthorization / guarantee of payment (App\Application\Health\Preauth).
 * No value is invented: the GOP validity period is insurer/owner configuration. While it is unset, the reviewer
 * gives valid_until on every approval. SLA targets live on the HEALTH_PREAUTHORIZATION case type (none seeded);
 * authority limits live in authority_limits (none seeded).
 */
return [
    'gop_validity_days' => env('HEALTH_PREAUTH_GOP_VALIDITY_DAYS'),
    // Authority type (authority_types catalogue) for the guaranteed amount; the catalogue has no preauthorization type yet.
    'authority_type' => env('HEALTH_PREAUTH_AUTHORITY_TYPE', 'CLAIM_SETTLE'),
];
