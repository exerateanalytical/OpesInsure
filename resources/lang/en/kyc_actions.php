<?php

declare(strict_types=1);

// KYC review actions (App\Filament\Shared\Actions\KycActions) and the KYC review screen (KycSubmissionResource).
return [
    'group' => 'KYC review',

    'kycAttachDocument' => ['label' => 'Attach document', 'help' => 'Adds one of the customer\'s documents to this KYC file.', 'done' => 'Document attached'],
    'kycDeclareSources' => ['label' => 'Declare source of funds / wealth', 'help' => 'Record the source of funds and/or source of wealth, with supporting documents.', 'done' => 'Sources declared',
        'missing' => 'Provide a source of funds and/or a source of wealth.'],
    'kycStartReview' => ['label' => 'Start review', 'help' => 'You become the reviewer of this KYC submission.', 'done' => 'Review started'],
    'kycSetLevel' => ['label' => 'Change KYC level', 'help' => 'The level can be raised but never set below the risk-based level.', 'done' => 'KYC level changed'],
    'kycAssessRisk' => ['label' => 'Assess customer risk', 'help' => 'Reviewer inputs for the customer risk rating.', 'done' => 'Risk assessment recorded',
        'too_many' => 'Too many product codes (max. 50) or declared factors (max. 20).'],
    'kycRecordScreening' => ['label' => 'Record screening result', 'help' => 'Record the result of a sanctions / PEP / watchlist check. A possible or confirmed match raises the level to Enhanced.', 'done' => 'Screening result recorded'],
    'kycRequestInformation' => ['label' => 'Request information', 'help' => 'The customer is asked to complete the file; any pending recommendation is cleared.', 'done' => 'Information requested'],
    'kycRecommend' => ['label' => 'Recommend decision', 'help' => 'Maker step: a different user holding the decision permission confirms it.', 'done' => 'Recommendation recorded'],
    'kycDecide' => ['label' => 'Decide', 'help' => 'Recommended outcome: :outcome. Confirm it, or return the file to review.', 'done' => 'Decision recorded'],
    'kycRescreen' => ['label' => 'Rescreen', 'help' => 'Runs a new screening round on this approved KYC.', 'done' => 'Rescreening started'],
    'kycRemediate' => ['label' => 'Start remediation', 'help' => 'Creates a new draft KYC; this one is kept unchanged.', 'done' => 'Remediation started'],

    'fields' => [
        'party' => 'Customer',
        'subject_kind' => 'Subject',
        'kyc_level' => 'KYC level',
        'status' => 'Status',
        'screening_status' => 'Screening',
        'submitted_at' => 'Submitted',
        'expires_at' => 'Expires',
        'document' => 'Document',
        'purpose' => 'Purpose',
        'source_of_funds' => 'Source of funds',
        'source_of_wealth' => 'Source of wealth',
        'description' => 'Description',
        'origin' => 'Origin',
        'evidence_documents' => 'Supporting documents',
        'reason' => 'Reason',
        'country_code' => 'Country (ISO code)',
        'product_codes' => 'Product codes',
        'channel' => 'Channel',
        'customer_type' => 'Customer type',
        'declared_factors' => 'Declared risk factors',
        'check' => 'Screening check',
        'result' => 'Result',
        'list_reference' => 'List reference',
        'notes' => 'Notes',
        'outcome' => 'Recommended outcome',
        'rationale' => 'Rationale',
        'confirm' => 'Confirm the recommendation',
        'confirm_help' => 'Switch off to return the file to review instead.',
    ],

    'codes' => [
        'level' => ['SIMPLIFIED' => 'Simplified', 'STANDARD' => 'Standard', 'ENHANCED' => 'Enhanced'],
        'kind' => ['INDIVIDUAL' => 'Individual', 'CORPORATE' => 'Corporate'],
        'check_status' => ['PENDING' => 'Pending', 'CLEAR' => 'Clear', 'POSSIBLE_MATCH' => 'Possible match', 'CONFIRMED_MATCH' => 'Confirmed match'],
        'outcome' => ['APPROVE' => 'Approve', 'REJECT' => 'Reject'],
    ],
];
