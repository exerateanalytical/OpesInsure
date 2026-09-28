<?php

declare(strict_types=1);

// Party golden-record stewardship, agent client registration and renewal sweep actions (staff desktop).
return [
    'group' => 'Golden record',
    'matchScan' => ['label' => 'Scan for duplicates', 'help' => 'Compares this party with the register and lists probable duplicates for review.', 'done' => 'Duplicate scan completed.'],
    'matchDismiss' => ['label' => 'Dismiss match candidate', 'help' => 'Records that the two parties are distinct persons or organizations.', 'done' => 'Match candidate dismissed.'],
    'addOwnership' => ['label' => 'Record ownership interest', 'help' => 'Records a shareholding, voting or control interest held in this organization.', 'done' => 'Ownership interest recorded.'],
    'endOwnership' => ['label' => 'End ownership interest', 'done' => 'Ownership interest ended.'],
    'mergeDecision' => ['label' => 'Decide merge request', 'help' => 'Approve or reject a pending merge. The approver must differ from the requester.', 'done' => 'Merge decision recorded.'],
    'mergeUnmerge' => ['label' => 'Reverse merge', 'help' => 'Restores the merged party and moves its records back.', 'done' => 'Merge reversed.'],
    'registerClient' => ['label' => 'Register client', 'help' => 'Registers a new individual client with the consent confirmed by the client.', 'done' => 'Client registered.'],
    'renewalSweep' => ['label' => 'Generate renewal cases', 'help' => 'Opens renewal cases for policies expiring within the chosen number of days.', 'done' => 'Renewal cases generated.'],
    'fields' => [
        'candidate' => 'Match candidate',
        'owner' => 'Owner',
        'percentage' => 'Percentage',
        'interest_type' => 'Interest type',
        'interest' => 'Ownership interest',
        'merge' => 'Merge request',
        'decision' => 'Decision',
        'full_name' => 'Full name',
        'phone' => 'Phone number',
        'city' => 'City',
        'consent_confirmed' => 'The client consents to the processing of their personal data',
        'days_ahead' => 'Days ahead',
    ],
    'interest_types' => ['SHAREHOLDING' => 'Shareholding', 'VOTING' => 'Voting rights', 'CONTROL' => 'Control by other means'],
    'decisions' => ['APPROVED' => 'Approve', 'REJECTED' => 'Reject'],
];
