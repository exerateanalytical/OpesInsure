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

    // Screening adapter (App\Application\Kyc\Screening\ScreeningAdapter). Only MANUAL exists; no lists ship.
    'screening' => [
        'adapter' => App\Application\Kyc\Screening\ManualScreeningAdapter::class,
        'checks' => ['SANCTIONS', 'PEP'],
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
