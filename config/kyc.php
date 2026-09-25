<?php

/*
 | REQ-KYC-001..003 — KYC configuration (App\Application\Kyc).
 | Every value below that encodes a regulatory choice is an owner decision and UNVERIFIED:
 | no CIMA / ANIF / CEMAC text in the repository fixes it.
 */
return [
    // Gate on policy issuance request (bind) and issuance approval (issue): OFF | WARN | ENFORCE.
    // Tenants override with tenants.settings.kyc.gate_mode. Default OFF until the owner confirms (UNVERIFIED).
    'gate_mode' => env('KYC_GATE_MODE', 'OFF'),

    // Level computed when no risk factor raises it. SIMPLIFIED is never computed automatically.
    'default_level' => 'STANDARD',

    // Months after approval before a refresh is due, per level. NULL = no fixed period (UNVERIFIED);
    // expiry then comes only from the attached documents' valid_until. kyc_level_requirements.refresh_months wins when set.
    'refresh_months' => ['SIMPLIFIED' => null, 'STANDARD' => null, 'ENHANCED' => null],

    // Window for GET /v1/kyc/expiring.
    'expiry_warning_days' => 30,

    // Corporate approval needs every identified UBO to hold an approved KYC, and no unresolved owners.
    'corporate' => ['require_ubo_kyc' => true],

    // Screening adapter (App\Application\Kyc\Screening\ScreeningAdapter). Owner decision 27: MANUAL_AUDITED until a
    // provider is integrated (legacy value MANUAL is read as MANUAL_AUDITED). No lists ship; automated screening is never claimed.
    'screening' => [
        'mode' => env('KYC_SCREENING_MODE', 'MANUAL_AUDITED'),
        'adapter' => App\Application\Kyc\Screening\ManualScreeningAdapter::class,
        'checks' => ['SANCTIONS', 'PEP'],
    ],

    /*
     | Owner decision 27 — risk-based KYC (App\Application\Kyc\Risk\CustomerRiskRatingService).
     | Every weight, score, band and period below is NULL (UNVERIFIED) until the owner / compliance sets it; no value
     | is invented. With NULLs the rating is UNRATED except where a hard trigger (sanctions / PEP) makes it HIGH.
     | Tenants override any key under tenants.settings.kyc.risk.
     */
    'risk' => [
        // factor => weight (NULL = not configured) and value => score (NULL = not configured).
        'factors' => [
            'customer' => ['weight' => null, 'scores' => ['INDIVIDUAL' => null, 'CORPORATE' => null]],
            'country' => ['weight' => null, 'scores' => []],            // ISO 3166-1 alpha-2 => score
            'product' => ['weight' => null, 'scores' => []],            // product code => score
            'channel' => ['weight' => null, 'scores' => []],            // e.g. FACE_TO_FACE, NON_FACE_TO_FACE, BROKER, MOBILE_APP => score
            // Workflow Data Master OCCUPATION_RISK: optional until configured (occupation catalogue PENDING_SOURCE).
            'occupation' => ['weight' => null, 'scores' => []],         // occupation code => score
        ],
        // Weighted score upper bounds (inclusive): score <= LOW => LOW, <= MEDIUM => MEDIUM, else HIGH. NULL = UNRATED.
        'bands' => ['LOW' => null, 'MEDIUM' => null],
        // Facts that make the rating HIGH whatever the score (decision 27 names PEP / sanctions).
        'hard_triggers' => ['PEP', 'PEP_POSSIBLE_MATCH', 'PEP_CONFIRMED_MATCH', 'SANCTIONS_POSSIBLE_MATCH', 'SANCTIONS_CONFIRMED_MATCH'],
        // Enhanced due diligence triggers (factor codes). HIGH_RISK = a HIGH rating. UBO_UNRESOLVED = ownership not traced.
        'edd_triggers' => ['HIGH_RISK', 'PEP', 'PEP_POSSIBLE_MATCH', 'PEP_CONFIRMED_MATCH', 'SANCTIONS_POSSIBLE_MATCH', 'SANCTIONS_CONFIRMED_MATCH'],
        // When source of funds / wealth must be declared before approval ("where required"): EDD and/or ratings. [] = never.
        'source_of_funds_required_when' => ['EDD'],
        'source_of_wealth_required_when' => ['EDD'],
        // Approval needs a rating other than UNRATED. false until the owner configures weights and bands.
        'require_rating' => false,
        // Periodic refresh (months after approval) and rescreening interval (months) per rating. NULL = no fixed period.
        'refresh_months' => ['LOW' => null, 'MEDIUM' => null, 'HIGH' => null, 'UNRATED' => null],
        'rescreen_months' => ['LOW' => null, 'MEDIUM' => null, 'HIGH' => null, 'UNRATED' => null],
    ],

    // Mobile / legacy pivot `purpose` values that evidence a catalogue canonical code.
    // REPRESENTATIVE_<purpose> evidences the same code for a corporate representative.
    'purpose_aliases' => [
        'NATIONAL_ID' => ['NATIONAL_ID', 'ID_FRONT', 'ID_BACK', 'ID_CARD', 'CNI'],
        'PASSPORT' => ['PASSPORT'],
        'RESIDENCE_PERMIT' => ['RESIDENCE_PERMIT'],
        'PROOF_OF_ADDRESS' => ['PROOF_OF_ADDRESS'],
        'BUSINESS_REGISTRATION' => ['BUSINESS_REGISTRATION', 'RCCM'],
        'TAX_ID_CERTIFICATE' => ['TAX_ID_CERTIFICATE', 'NIU'],
        'FINANCIAL_STATEMENTS' => ['FINANCIAL_STATEMENTS'],
    ],
];
