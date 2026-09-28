<?php

declare(strict_types=1);

/** Compliance and trust desktop actions (App\Filament\Shared\Actions\ComplianceActions, GovernanceRegisters page). */
return [
    'case_group' => 'Case actions',
    'caseOpen' => ['label' => 'Open compliance case', 'help' => 'Opens a compliance case on the case engine for the selected subject.', 'done' => 'Compliance case opened.'],
    'caseLinkEvidence' => ['label' => 'Link evidence', 'help' => 'Attach a document or an external reference as evidence. A document or a reference is required.', 'done' => 'Evidence linked.'],
    'caseAddFinding' => ['label' => 'Add finding', 'help' => 'Record a finding on this case. A closed case must be reopened first.', 'done' => 'Finding recorded.'],
    'findingWithdraw' => ['label' => 'Withdraw finding', 'help' => 'Withdrawing a finding cancels its open corrective actions.', 'done' => 'Finding withdrawn.'],
    'findingPlanAction' => ['label' => 'Plan corrective action', 'help' => 'Plan a corrective action on an open finding; a task is added to the owner\'s work case.', 'done' => 'Corrective action planned.'],
    'actionEvent' => ['label' => 'Update corrective action', 'help' => 'Start, complete or cancel a corrective action. Cancelling requires a reason.', 'done' => 'Corrective action updated.'],
    'actionVerify' => ['label' => 'Verify corrective action', 'help' => 'Verification must be done by someone other than the person who completed the action.', 'done' => 'Verification recorded.'],
    'dsrReceive' => ['label' => 'Record data subject request', 'help' => 'Registers a data subject request received from a customer.', 'done' => 'Data subject request recorded.'],
    'accessRequest' => ['label' => 'Request privileged access', 'help' => 'Creates a time-bound privileged access request. A different officer must approve it.', 'done' => 'Privileged access requested.'],
    'fraudAlert' => ['label' => 'Raise fraud alert', 'help' => 'Choose an active fraud rule, or leave it empty and give a manual risk score.', 'done' => 'Fraud alert raised.'],
    'reportPrepare' => ['label' => 'Prepare report run', 'help' => 'Prepares a regulatory report run from an active report definition.', 'done' => 'Report run prepared.'],
    'reportAcknowledge' => ['label' => 'Record acknowledgement', 'help' => 'Record the regulator\'s acknowledgement reference.', 'done' => 'Acknowledgement recorded.'],
    'reportFail' => ['label' => 'Record submission failure', 'help' => 'The run returns to retry pending.', 'done' => 'Submission failure recorded.'],
    'governanceCreate' => ['label' => 'Add entry', 'help' => 'Adds an entry to the selected governance register.', 'done' => 'Register entry added.'],
    'governanceUpdate' => ['label' => 'Edit entry', 'help' => 'Editing an approved exit plan returns it to draft for a new approval.', 'done' => 'Register entry updated.'],
    'exitPlanApprove' => ['label' => 'Approve exit plan', 'help' => 'The approver must be different from the person who prepared the plan.', 'done' => 'Exit plan approved.'],

    'governance' => [
        'title' => 'Governance registers',
        'switch' => 'Register',
        'registers' => [
            'ict-assets' => 'ICT assets',
            'ict-incidents' => 'ICT incidents',
            'vendors' => 'Vendors',
            'outsourcing-contracts' => 'Outsourcing contracts',
            'due-diligence-reviews' => 'Due diligence reviews',
            'exit-plans' => 'Exit plans',
        ],
    ],

    'sections' => ['findings' => 'Findings', 'corrective_actions' => 'Corrective actions'],

    'fields' => [
        'type' => 'Type', 'subject_type' => 'Subject type', 'subject_id' => 'Subject ID', 'severity' => 'Severity', 'owner_id' => 'Owner',
        'review_due_on' => 'Review due on', 'description' => 'Description', 'document_id' => 'Document ID', 'external_reference' => 'External reference',
        'finding_id' => 'Finding', 'corrective_action_id' => 'Corrective action', 'title' => 'Title', 'category' => 'Category', 'reason' => 'Reason',
        'owner_user_id' => 'Owner', 'due_on' => 'Due on', 'event' => 'Event', 'notes' => 'Notes', 'accepted' => 'Accepted',
        'party_id' => 'Customer', 'assigned_to' => 'Assigned to', 'user_id' => 'User', 'purpose' => 'Purpose', 'justification' => 'Justification',
        'starts_at' => 'Starts at', 'expires_at' => 'Expires at', 'scope' => 'Scope', 'alert_type' => 'Alert type', 'fraud_rule_version_id' => 'Fraud rule',
        'risk_score' => 'Risk score', 'signals' => 'Signals', 'review_due_at' => 'Review due at', 'definition_id' => 'Report definition',
        'period_key' => 'Period', 'payload' => 'Report data', 'status' => 'Status', 'created_at' => 'Created', 'version' => 'Version',
        'asset_code' => 'Asset code', 'name' => 'Name', 'criticality' => 'Criticality', 'vendor_id' => 'Vendor', 'ict_asset_id' => 'ICT asset',
        'detected_at' => 'Detected at', 'resolved_at' => 'Resolved at', 'reported_externally_at' => 'Reported externally at',
        'compliance_case_id' => 'Compliance case', 'vendor_code' => 'Vendor code', 'services' => 'Services', 'is_outsourcing' => 'Outsourcing',
        'risk_rating' => 'Risk rating', 'contract_reference' => 'Contract reference', 'service_description' => 'Service description',
        'start_on' => 'Start on', 'end_on' => 'End on', 'contract_id' => 'Contract', 'review_date' => 'Review date', 'outcome' => 'Outcome',
        'next_review_on' => 'Next review on', 'summary' => 'Summary', 'last_tested_on' => 'Last tested on', 'incident_number' => 'Incident number',
        'key' => 'Key', 'value' => 'Value',
    ],

    'options' => [
        'severity' => ['LOW' => 'Low', 'MEDIUM' => 'Medium', 'HIGH' => 'High', 'CRITICAL' => 'Critical'],
        'event' => ['start' => 'Start', 'complete' => 'Complete', 'cancel' => 'Cancel'],
        'dsr_type' => ['ACCESS' => 'Access', 'CORRECTION' => 'Correction', 'ERASURE' => 'Erasure', 'RESTRICTION' => 'Restriction', 'PORTABILITY' => 'Portability', 'OBJECTION' => 'Objection'],
        'subject_type' => ['PAYMENT' => 'Payment', 'QUOTE' => 'Quote', 'POLICY' => 'Policy', 'CLAIM' => 'Claim', 'COMMISSION' => 'Commission', 'USER' => 'User'],
        'outcome' => ['SATISFACTORY' => 'Satisfactory', 'CONDITIONAL' => 'Conditional', 'UNSATISFACTORY' => 'Unsatisfactory'],
    ],
];
