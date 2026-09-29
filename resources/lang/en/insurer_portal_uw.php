<?php

// P3 (owner 2026-09-29, D4 lifted): underwriting decisions on the underwriting case page (admin + /insurer).
return [
    'uwDecide' => [
        'label' => 'Record decision',
        'help' => 'Approve, approve with conditions or decline this case. Open referrals must be resolved first.',
        'done' => 'Underwriting decision recorded',
    ],
    'uwCounterOffer' => [
        'label' => 'Counter-offer',
        'help' => 'Propose different terms to the applicant instead of accepting as submitted.',
        'done' => 'Counter-offer recorded',
    ],
];
