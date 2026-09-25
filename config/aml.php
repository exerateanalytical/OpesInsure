<?php

/*
 | Agent E8 — REQ-AML-001 / REQ-KYC-004 list screening (App\Application\Compliance\Aml\Screening).
 | No list data ships with the platform. Values that encode a compliance choice are owner decisions (UNVERIFIED).
 | Tenants override under tenants.settings.aml.screening.*.
 */
return [
    'screening' => [
        // ComplianceGate at bind / issue / claim payout: OFF | WARN | ENFORCE (tenants.settings.aml.screening_gate_mode).
        // Default OFF until the owner confirms (UNVERIFIED), same pattern as kyc.gate_mode.
        'gate_mode' => env('AML_SCREENING_GATE_MODE', 'OFF'),

        // Minimum NameMatcher score (0..1) that raises a hit. PLATFORM_PROVISIONAL tuning default, not a regulatory value.
        'match_threshold' => (float) env('AML_SCREENING_MATCH_THRESHOLD', 0.85),

        // Periodic rescreen interval in days (aml:rescreen). NULL = no periodic rescreen until the owner sets one (UNVERIFIED).
        'rescreen_interval_days' => env('AML_SCREENING_RESCREEN_DAYS') !== null ? (int) env('AML_SCREENING_RESCREEN_DAYS') : null,

        // Rescreen every tenant customer when a new list version is activated.
        'rescreen_on_activation' => true,

        // Maximum entries accepted in one list import.
        'max_import_entries' => 200000,
    ],
];
