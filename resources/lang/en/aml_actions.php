<?php

return [
    'nav' => [
        'hits' => 'AML screening hits',
        'lists' => 'Screening lists',
        'monitoring' => 'Transaction monitoring',
        'str' => 'Suspicious transaction reports',
    ],
    'empty' => 'Nothing to show',
    'monitoring_inactive' => 'No active monitoring rule: transaction monitoring is inactive until a rule is configured',

    'screenParty' => ['label' => 'Screen party', 'help' => 'Screen this customer against the active PEP, sanctions and watchlists.', 'done' => 'Party screened'],
    'riskRate' => ['label' => 'Rate AML risk', 'help' => 'Compute the customer AML risk rating. A HIGH rating opens an enhanced due diligence case.', 'done' => 'AML risk rated'],
    'hitPropose' => ['label' => 'Propose disposition', 'help' => 'Propose a disposition for this match; a second officer must approve it.', 'done' => 'Disposition proposed'],
    'hitDecide' => ['label' => 'Decide disposition', 'help' => 'Approve or reject the proposed disposition (four eyes).', 'done' => 'Disposition decided'],
    'listCreate' => ['label' => 'New screening list', 'help' => 'Register a PEP, sanctions or watchlist source.', 'done' => 'Screening list created'],
    'listImport' => ['label' => 'Import list version', 'help' => 'Import a new version of a list. It is used for screening only after a second officer approves it.', 'done' => 'List version imported'],
    'listVersionDecide' => ['label' => 'Decide list version', 'help' => 'Approve (activate) or reject this imported version (four eyes).', 'done' => 'List version decided'],
    'monitorEvaluate' => ['label' => 'Evaluate transaction', 'help' => 'Evaluate a transaction against the active monitoring rules.', 'done' => 'Transaction evaluated',
        'result' => 'Result: :status, :alerts alert(s)'],
    'strDraft' => ['label' => 'Draft STR', 'help' => 'Confidential. Never inform the customer (tipping-off is prohibited).', 'done' => 'STR drafted'],
    'strSubmit' => ['label' => 'Submit STR', 'help' => 'Record the submission to the financial intelligence unit. The submitter must differ from the drafter.', 'done' => 'STR submitted'],

    'fields' => [
        'country_code' => 'Country (ISO code)', 'product_codes' => 'Product codes', 'channel' => 'Channel', 'customer_type' => 'Customer type', 'reason' => 'Reason',
        'disposition' => 'Disposition', 'rationale' => 'Rationale', 'decision' => 'Decision', 'note' => 'Note',
        'code' => 'Code', 'name' => 'Name', 'list_type' => 'List type', 'publisher' => 'Publisher',
        'source' => 'List', 'csv_content' => 'CSV content', 'csv_help' => 'Header row: entry_ref,name,aliases,date_of_birth,country (aliases separated by |).',
        'source_reference' => 'Source reference', 'subject_type' => 'Subject type', 'subject_id' => 'Subject ID', 'party_id' => 'Party ID',
        'facts' => 'Facts', 'fact' => 'Fact', 'value' => 'Value', 'party' => 'Customer', 'grounds' => 'Grounds for suspicion', 'regulator_reference' => 'Regulator reference',
    ],
    'columns' => [
        'party' => 'Customer', 'matched_name' => 'Matched name', 'list_type' => 'List type', 'entry_ref' => 'Entry reference', 'score' => 'Score', 'status' => 'Status',
        'disposition' => 'Disposition', 'created_at' => 'Created', 'source' => 'List', 'version' => 'Version', 'format' => 'Format', 'entry_count' => 'Entries',
        'source_reference' => 'Source reference', 'activated_at' => 'Activated', 'grounds' => 'Grounds', 'regulator_reference' => 'Regulator reference',
        'submitted_at' => 'Submitted', 'rule' => 'Rule', 'risk_points' => 'Risk points', 'conditions' => 'Conditions', 'effective_from' => 'Effective from', 'effective_until' => 'Effective until',
    ],
    'codes' => [
        'customer_type' => ['INDIVIDUAL' => 'Individual', 'CORPORATE' => 'Corporate'],
        'disposition' => ['FALSE_POSITIVE' => 'False positive', 'TRUE_MATCH' => 'True match', 'ESCALATED' => 'Escalated'],
        'decision' => ['APPROVE' => 'Approve', 'REJECT' => 'Reject'],
        'list_type' => ['PEP' => 'Politically exposed persons', 'SANCTIONS' => 'Sanctions list', 'WATCHLIST' => 'Watchlist'],
        'subject_type' => ['PAYMENT' => 'Payment', 'POLICY' => 'Policy', 'CLAIM' => 'Claim', 'COMMISSION' => 'Commission', 'REFUND' => 'Refund'],
        'hit_status' => ['OPEN' => 'Open', 'PROPOSED' => 'Proposed', 'DISPOSED' => 'Disposed'],
        'version_status' => ['PENDING_APPROVAL' => 'Pending approval', 'ACTIVE' => 'Active', 'SUPERSEDED' => 'Superseded', 'REJECTED' => 'Rejected'],
        'str_status' => ['DRAFT' => 'Draft', 'SUBMITTED' => 'Submitted'],
    ],
];
