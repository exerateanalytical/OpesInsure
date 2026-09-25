<?php

/*
 | REQ-SET-004 — configuration inheritance & precedence (SCF governing rules).
 |
 | Levels, most general first: Platform → Insurer → Broker agreement → Broker internal → Branch → User.
 | Each key declares:
 |   - default:        the platform default (a PLATFORM override published through governance replaces it)
 |   - restriction:    how "more restrictive" is measured when a lower level overrides:
 |                       max    → numeric, lower level must be <= inherited value (e.g. a discount cap)
 |                       min    → numeric, lower level must be >= inherited value (e.g. a KYC level)
 |                       subset → list, lower level must be a subset of the inherited list
 |                       flag   → boolean permission, lower level may only turn true into false
 |                       fixed  → cannot be changed below the highest level in overridable_at
 |   - overridable_at: levels allowed to set the key (not every value is overridable: a broker cannot redefine cover,
 |                     an agent cannot change the premium formula).
 */
return [
    'levels' => ['PLATFORM', 'INSURER', 'BROKER_AGREEMENT', 'BROKER', 'BRANCH', 'USER'],

    'keys' => [
        'quote.max_discount_basis_points' => ['default' => 1500, 'restriction' => 'max', 'overridable_at' => ['PLATFORM', 'INSURER', 'BROKER_AGREEMENT', 'BROKER', 'BRANCH', 'USER']],
        'quote.validity_days' => ['default' => 30, 'restriction' => 'max', 'overridable_at' => ['PLATFORM', 'INSURER', 'BROKER_AGREEMENT', 'BROKER', 'BRANCH']],
        'underwriting.referral_threshold_minor' => ['default' => 500_000_000, 'restriction' => 'max', 'overridable_at' => ['PLATFORM', 'INSURER', 'BROKER_AGREEMENT', 'BROKER', 'BRANCH', 'USER']],
        'payments.allowed_methods' => ['default' => ['MOBILE_MONEY', 'CARD', 'BANK_TRANSFER', 'CASH'], 'restriction' => 'subset', 'overridable_at' => ['PLATFORM', 'INSURER', 'BROKER_AGREEMENT', 'BROKER', 'BRANCH']],
        'payments.cash_collection_allowed' => ['default' => true, 'restriction' => 'flag', 'overridable_at' => ['PLATFORM', 'INSURER', 'BROKER_AGREEMENT', 'BROKER', 'BRANCH', 'USER']],
        'kyc.minimum_level' => ['default' => 1, 'restriction' => 'min', 'overridable_at' => ['PLATFORM', 'INSURER', 'BROKER_AGREEMENT', 'BROKER', 'BRANCH']],
        'policy.grace_period_days' => ['default' => 30, 'restriction' => 'max', 'overridable_at' => ['PLATFORM', 'INSURER', 'BROKER_AGREEMENT']],
        'product.coverage_definition' => ['default' => null, 'restriction' => 'fixed', 'overridable_at' => ['PLATFORM', 'INSURER']],
        'tariff.premium_formula' => ['default' => null, 'restriction' => 'fixed', 'overridable_at' => ['PLATFORM', 'INSURER']],
    ],
];
