<?php

declare(strict_types=1);

// Broker portal (/broker) servicing & operations actions (owner decision 2026-09-29, portals writable).
return [
    'serviceRequest' => ['label' => 'Servicing request', 'help' => 'Send an endorsement, document re-issue or other servicing request for this policy to the insurer.', 'done' => 'Servicing request sent.'],
    'assistedClaim' => ['label' => 'Declare a claim for a customer', 'help' => 'Report a loss on behalf of a customer of your book. The claim follows the normal claims process.', 'done' => 'Claim declared.'],
    'inviteStaff' => ['label' => 'Invite a staff member', 'help' => 'Send an invitation to join your brokerage. You can only grant a role you are allowed to grant.', 'done' => 'Invitation created.', 'code' => 'Invitation code (share it with the invitee)'],
    'renewalQuote' => ['label' => 'Re-quote renewal', 'done' => 'Renewal quote created'],
    'renewalComplete' => ['label' => 'Link successor policy', 'done' => 'Successor policy linked'],
    'fields' => [
        'type' => 'Request type',
        'reason' => 'Details',
        'policy' => 'Policy',
        'loss_occurred_at' => 'Date of loss',
        'loss_location' => 'Place of loss',
        'estimated_loss' => 'Estimated loss (minor units)',
        'description' => 'What happened',
        'recipient_email' => 'Email',
        'recipient_phone' => 'Phone (E.164)',
        'role' => 'Role',
        'successor' => 'Successor policy',
    ],
    'types' => [
        'ENDORSEMENT' => 'Endorsement',
        'CANCELLATION_REVIEW' => 'Cancellation review',
        'ADDRESS_CHANGE' => 'Address change',
        'VEHICLE_CHANGE' => 'Vehicle change',
        'BENEFICIARY_CHANGE' => 'Beneficiary change',
        'DOCUMENT_REISSUE' => 'Certificate / document re-issue',
    ],
];
