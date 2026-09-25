<?php

return [
    'tabs' => ['overview' => 'Overview', 'governance' => 'Governance', 'completeness' => 'Completeness', 'tests' => 'Tests & sandbox', 'changes' => 'Changes'],
    'overview' => ['class' => 'Class', 'family' => 'Family', 'branches' => 'CIMA branches', 'coverages' => 'Coverages'],
    'stages' => ['DRAFT' => 'Draft', 'CONFIGURATION' => 'Configuration', 'TECHNICAL_REVIEW' => 'Technical review', 'COMPLIANCE_REVIEW' => 'Compliance review',
        'BUSINESS_APPROVAL' => 'Business approval', 'SANDBOX_TESTS' => 'Sandbox tests', 'READY' => 'Ready', 'PUBLISHED' => 'Published', 'REJECTED' => 'Rejected'],
    'governance' => ['workflow' => 'Governance workflow', 'next' => 'Next step', 'scheduled' => 'Publication scheduled for', 'target_market' => 'Target market',
        'prohibited_market' => 'Prohibited market', 'review_date' => 'Next review', 'when' => 'When', 'from' => 'From', 'to' => 'To', 'decision' => 'Decision',
        'notes' => 'Notes', 'no_history' => 'No governance step recorded yet.'],
    'completeness' => ['status' => 'Completeness', 'COMPLETE' => 'Complete', 'COMPLETE_WITH_WARNINGS' => 'Complete with warnings', 'INCOMPLETE' => 'Incomplete — publication blocked',
        'blocking' => 'Blocking', 'warning' => 'Warning'],
    'checks' => ['REGULATORY_MAPPING' => 'Regulatory mapping', 'CIMA_BRANCH_AUTHORIZED' => 'CIMA branch authorization', 'PRICING_MODE' => 'Pricing mode',
        'UNDERWRITING_MODE' => 'Underwriting mode', 'DOCUMENT_MAPPING' => 'Document mapping', 'ACCOUNTING_MAPPING' => 'Accounting mapping',
        'CLAIMS_CONFIGURATION' => 'Claims configuration', 'PRODUCT_TESTS' => 'Product tests', 'CAPABILITY_PROFILE' => 'Capability profile', 'DOCUMENT_WARNING' => 'Documents', 'DOCUMENT_ENGINE_WARNING' => 'Document engine',
        'COVERAGES' => 'Coverages', 'WORDING_EN_FR' => 'EN/FR wording', 'GOVERNANCE_OWNER' => 'Product owner', 'REVIEW_DATE' => 'Review date'],
    'tests' => ['latest' => 'Latest run', 'stale' => 'configuration changed since — re-run required', 'never_run' => 'The test policy pack has never been run.',
        'code' => 'Code', 'name' => 'Name', 'expected' => 'Expected', 'no_cases' => 'No test case yet.', 'result' => 'Result', 'failed_cases' => 'Failed cases'],
    'diff' => ['no_base' => 'First version: nothing to compare with.', 'none' => 'No configuration change against the base version.', 'path' => 'Field', 'change' => 'Change', 'from' => 'Before', 'to' => 'After'],
    'actions' => ['advance' => 'Advance to next step', 'reject' => 'Reject', 'publish' => 'Publish / schedule', 'publish_at' => 'Publish at (leave empty for now)',
        'add_case' => 'Add test case', 'run_tests' => 'Run test pack', 'attributes' => 'Governance attributes', 'notes' => 'Notes', 'reason' => 'Reason',
        'facts' => 'Risk facts', 'expected_eligibility' => 'Expected eligibility', 'expected_total' => 'Expected total premium (minor units)', 'owner' => 'Product owner',
        'code' => 'Code', 'name' => 'Name', 'advanced' => 'Governance step completed.', 'tests_done' => 'Test pack run finished.'],
];
