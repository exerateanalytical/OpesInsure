<?php

// S3 2026-09-29: web entry points for the last API actions without a UI (renewal reassignment, workspace, vehicle
// documents, agent profile). Same permission + same service as the API.
return [
    'renewalReassign' => [
        'label' => 'Reassign',
        'help' => 'Hand this renewal case to a colleague who works renewals, or leave it unassigned.',
        'done' => 'Renewal case reassigned.',
    ],
    'workspaceSave' => [
        'label' => 'My workspace',
        'help' => 'Remember this portal for you: current language and the page you want to start from.',
        'done' => 'Workspace saved.',
    ],
    'fields' => [
        'assignee' => 'Assignee',
        'unassigned' => 'Unassigned',
        'reason' => 'Reason (optional)',
        'start_page' => 'Start page',
    ],
    'start_pages' => [
        'dashboard' => 'Dashboard',
        'reports' => 'Reports',
        'search' => 'Search',
        'help' => 'Help',
    ],
    'renewal' => [
        'assignee_invalid' => 'Choose an active colleague of this organisation who may work renewals.',
        'not_open' => 'Only an open renewal case can be reassigned.',
        'unchanged' => 'The case is already assigned that way.',
    ],
    'assets' => [
        'docs_t' => 'Vehicle documents',
        'none' => 'No document attached to this vehicle yet.',
        'purpose' => 'Document',
        'file' => 'File (JPG, PNG or PDF)',
        'attach' => 'Upload and attach',
        'pick_file' => 'Choose a file first.',
        'attached' => 'Document attached to the vehicle.',
        'scan' => 'Read details',
        'scanned' => 'Reading requested. If the details cannot be read automatically, confirm them yourself.',
        'confirm' => 'Confirm details',
        'confirm_help' => 'Type the details exactly as they appear on the document. They will be checked by our team.',
        'confirm_save' => 'Save details',
        'confirmed' => 'Details saved. Our team will review the document.',
        'purposes' => [
            'VEHICLE_REGISTRATION' => 'Registration certificate (carte grise)',
            'DRIVING_LICENCE' => 'Driving licence',
            'VEHICLE_PHOTO' => 'Vehicle photo',
            'OTHER' => 'Other document',
        ],
        'scan_status' => [
            'PENDING' => 'Security check pending',
            'CLEAN' => 'Checked',
            'INFECTED' => 'Rejected (unsafe file)',
            'FAILED' => 'Check failed',
        ],
        'ocr' => [
            'MANUAL_REVIEW_REQUIRED' => 'Could not be read automatically',
            'CUSTOMER_CONFIRMED' => 'Details confirmed by you',
            'EXTRACTED' => 'Details read',
        ],
    ],
    'agent_profile' => [
        'title' => 'Agent profile',
        'help' => 'Your agent details and payout number. Agent code:',
        'national_id' => 'National ID number (leave empty to keep)',
        'momo' => 'Mobile money number for payouts',
        'code' => 'Security code (SMS)',
        'code_sent' => 'A security code was sent to your phone. Enter it and save again to change your payout number.',
        'saved' => 'Agent profile saved.',
    ],
];
