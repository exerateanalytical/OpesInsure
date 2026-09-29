<?php

// S9 — bulk broker onboarding (/admin → Administration → Broker onboarding, API /api/v1/platform/broker-onboarding).
return [
    'target_label' => 'Bulk broker onboarding',
    'nav' => 'Broker onboarding',
    'group' => 'Administration',
    'model' => 'broker onboarding batch',
    'models' => 'broker onboarding batches',
    'intro' => 'Download the template, fill one row per brokerage, upload it and check the preview. Submit the batch: another administrator approves it, then each valid row gets its broker organisation, licence, branches and an invitation for its administrator. Brokerages already on the platform are skipped.',
    'summary' => ':rows rows · :new new · :duplicates already on the platform · :errors errors · :created created · :failed failed',
    'columns' => [
        'uploaded' => 'Uploaded', 'file' => 'File', 'status' => 'Status', 'summary' => 'Rows', 'maker' => 'Uploaded by', 'checker' => 'Approved by',
    ],
    'actions' => [
        'template_csv' => 'Template (CSV)', 'template_xlsx' => 'Template (Excel)', 'upload' => 'Upload brokers', 'preview' => 'Preview rows',
        'report' => 'Download report', 'submit' => 'Submit for approval', 'approve' => 'Approve & create', 'reject' => 'Reject', 'cancel' => 'Cancel',
    ],
    'fields' => [
        'file' => 'CSV or Excel file', 'reason' => 'Comment', 'note' => 'Note',
        'legal_name' => 'Legal name', 'trade_name' => 'Trade name', 'rccm' => 'RCCM', 'niu' => 'NIU', 'licence_number' => 'Licence number',
        'licence_expires_on' => 'Licence expiry date', 'city' => 'City', 'address' => 'Address', 'phone' => 'Phone', 'email' => 'Email',
        'admin_name' => 'Administrator name', 'admin_phone' => 'Administrator phone', 'admin_email' => 'Administrator email', 'branches' => 'Branches', 'locale' => 'Language',
    ],
    'help' => [
        'upload' => 'Use the template columns. Phones in +237 format; dates as YYYY-MM-DD or DD/MM/YYYY; branches as "Name@City|Name@City".',
        'approve' => 'This creates :n broker organisations, their licences, branches and administrator invitations (sent by SMS/email).',
    ],
    'notify' => [
        'uploaded' => 'File checked',
        'imported' => ':created brokers onboarded, :failed failed',
    ],
    'empty' => [
        'heading' => 'No broker onboarding yet',
        'description' => 'Download the template, fill it with your partner brokers and upload it.',
    ],
    'preview' => [
        'row' => 'Row', 'legal_name' => 'Brokerage', 'niu' => 'NIU', 'licence_number' => 'Licence', 'admin' => 'Administrator',
        'status' => 'Status', 'reason' => 'Details', 'delivery' => 'Invitation',
    ],
    'status' => [
        'new' => 'Ready', 'created' => 'Created', 'duplicate' => 'Already on the platform', 'skipped_duplicate' => 'Skipped (already on the platform)',
        'error' => 'Error', 'skipped_error' => 'Skipped (error)', 'failed' => 'Failed', 'linked_register' => 'Linked to the official register entry',
    ],
    'errors' => [
        'required' => ':field is required.',
        'niu_format' => 'NIU must be 1 letter, 12 digits and 1 letter (e.g. M012345678901A).',
        'rccm_format' => 'RCCM is not valid (e.g. RC/DLA/2019/B/1234).',
        'licence_format' => 'Licence number is not valid (letters, digits, / - . only, with at least one digit).',
        'date_format' => 'Licence expiry date is not a valid date (YYYY-MM-DD or DD/MM/YYYY).',
        'licence_expired' => 'The licence expired on :date.',
        'phone_format' => ':field must be a Cameroon number (+237 then 9 digits starting with 2 or 6).',
        'email_format' => ':field is not a valid email address.',
        'admin_contact' => 'Give the administrator a phone or an email to send the invitation to.',
        'repeated' => ':field is repeated in the file (row :row).',
    ],
    'matches' => [
        'tenant' => 'Organisation already exists: :name',
        'identifier' => ':type already registered on the platform',
        'licence' => 'Licence :number already registered',
        'partner' => 'Broker already registered: :name',
        'contact' => 'Contact :value already belongs to another party',
    ],
    'invite' => [
        'sms' => 'OpesInsure: hello :name, you are invited to manage :broker. Invitation code: :code (valid :days days). App: :url/download',
        'email_subject' => 'Your OpesInsure broker administrator invitation — :broker',
        'email_body' => "Hello :name,\n\n:broker has been onboarded on OpesInsure and you are its administrator.\nInstall the app (:url/download), sign in with this phone or email, then enter the invitation code:\n\n:code\n\nThe code is valid for :days days.\n\nOpesInsure",
    ],
];
