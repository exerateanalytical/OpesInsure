<?php

return [
    /*
     | REQ-DUP-009 / REQ-CMP-002: default response period for a data-subject request received without an
     | explicit due_on (legacy compliance/data-subject-requests contract). PLATFORM_PROVISIONAL — the
     | statutory period is an owner question; 30 only preserves the previous hard-coded behaviour.
     | Set to null to force callers to send due_on.
     */
    'dsr' => [
        'default_response_days' => env('COMPLIANCE_DSR_DEFAULT_RESPONSE_DAYS', 30),
        'default_response_days_label' => 'PLATFORM_PROVISIONAL',
    ],
];
