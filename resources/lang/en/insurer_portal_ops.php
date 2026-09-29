<?php

declare(strict_types=1);

// /insurer — reinsurance, documents and organisation screens (portal agent P4, owner decision 2026-09-29).
return [
    'nav' => [
        'organisation' => 'Organisation',
        'invitations' => 'Staff invitations',
        'documents' => 'Documents',
        'document_register' => 'Document register',
    ],
    'staff' => [
        'role_not_allowed' => 'This role cannot be granted from the insurer portal.',
    ],
    'branches' => [
        'add' => 'Add branch',
        'code' => 'Code',
        'name' => 'Name',
        'phone' => 'Phone',
        'email' => 'Email',
        'duplicate' => 'A branch with this code already exists.',
        'added' => 'Branch added',
    ],
    // Document register (GeneratedDocumentResource), shared by /admin and /insurer (R7 2026-09-29).
    'register' => [
        'revoke_replace' => 'Revoke / replace',
        'action' => 'Action',
        'revoke' => 'Revoke',
        'replace' => 'Replace',
        'cancel' => 'Cancel',
        'replacement' => 'Replacement document',
        'reason' => 'Reason',
        'tamper_identify' => 'Tamper check a PDF',
        'tamper_check' => 'Tamper check',
        'tamper_help' => 'The file is hashed and compared with the registry original and its platform signature. The file is not kept.',
        'pdf_to_check' => 'PDF to check',
        'number' => 'Document number',
        'carrier_original' => 'carrier original',
        'type' => 'Type',
        'policy' => 'Policy',
        'subject' => 'Subject',
        'issuer' => 'Issuer',
        'carrier' => 'Carrier',
        'language' => 'Language',
        'verification_code' => 'Verification code',
        'template_version' => 'Tpl v',
        'tier' => 'Tier',
        'status' => 'Status',
        'issuer_type' => 'Issuer type',
        'carrier_originals' => 'Carrier originals',
        'upload' => 'Upload carrier document',
        'document_type' => 'Document type',
        'issue_date' => 'Issue date',
        'carrier_document_number' => 'Carrier document number',
        'carrier_version' => 'Carrier version',
        'subject_key' => 'Vehicle registration / member / shipment (optional)',
        'file' => 'File',
        'history_tab' => 'Revoke / replace history',
        'lookups_tab' => 'Verification lookups',
    ],
];
