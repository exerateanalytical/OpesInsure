<?php

// Q8 underwriting workbench (UND-001..020): case tabs, dashboard, performance & SLA, information requests.
return [
    'dashboard' => 'Underwriting dashboard',
    'assigned_cases' => 'My assigned cases',
    'view_all' => 'View all',
    'by_status' => 'Cases by status',
    'performance' => 'Performance & SLA',
    'empty' => 'Nothing to show yet',
    'yes' => 'Yes',
    'no' => 'No',
    'restricted' => 'Restricted',
    'medical_withheld' => 'Medical information withheld',
    'medical_masked' => ':count medical item(s) withheld: your role does not hold medical document access.',
    'documents_withheld' => ':count document(s) withheld by document security level.',
    'not_evaluated' => 'The case has not been evaluated by the rules engine yet. Use "Evaluate" to compute the recommendation and risk score.',
    'no_information_request' => 'No additional information has been requested on this proposal.',
    'coverage_changes_note' => 'Coverage or premium changes are proposed through a counter-offer or conditions on the decision; the offered terms are never edited in place.',
    'requirements_note' => 'Inspection and medical requirements are raised with "Request information" (kind Inspection or Medical) and returned by the proposer.',
    'kind_not_allowed' => 'You may not request this kind of item.',

    'sections' => ['case' => 'Underwriting case'],

    'tabs' => [
        'profile' => 'Applicant & risk', 'policy_history' => 'Policy history', 'claims_history' => 'Claims history', 'questionnaire' => 'Questionnaire',
        'documents' => 'Supporting documents', 'risk_assessment' => 'Risk assessment', 'risk_score' => 'Risk score', 'coverage' => 'Coverage',
        'pricing' => 'Pricing & premium', 'information_request' => 'Information request', 'requirements' => 'Inspection & medical', 'supervisor' => 'Supervisor approval',
    ],

    'list' => ['all' => 'All', 'mine' => 'Assigned to me', 'unassigned' => 'Unassigned', 'awaiting' => 'Awaiting information', 'overdue' => 'Overdue'],

    'kpi' => [
        'open' => 'Open cases', 'unassigned' => 'Unassigned', 'mine' => 'Assigned to me', 'awaiting_information' => 'Awaiting information',
        'decision_pending' => 'Decision pending', 'open_referrals' => 'Open referrals', 'overdue' => 'Overdue',
    ],

    'perf' => [
        'period' => 'Period', 'last_days' => 'Last :days days', 'decisions' => 'Decisions', 'avg_turnaround' => 'Average turnaround',
        'sla_met' => 'Decided within due date', 'overdue_open' => 'Open cases past due date', 'by_underwriter' => 'By underwriter',
    ],

    'kinds' => ['DOCUMENT' => 'Document', 'ANSWER' => 'Answer', 'CLARIFICATION' => 'Clarification', 'INSPECTION' => 'Inspection', 'MEDICAL' => 'Medical'],

    'fields' => ['items' => 'Items requested', 'kind' => 'Kind', 'code' => 'Code (UPPER_SNAKE)', 'description' => 'Description', 'mandatory' => 'Mandatory', 'message' => 'Message to the proposer'],

    'uwRequestInformation' => [
        'label' => 'Request information',
        'help' => 'Ask the proposer for exact items: documents, answers, clarifications, inspection or medical requirements. The case waits for their response.',
        'done' => 'Information requested',
    ],

    'f' => [
        'applicant' => 'Applicant', 'party_type' => 'Party type', 'party_status' => 'Party status', 'identity' => 'Identity', 'profile' => 'Profile',
        'proposal' => 'Proposal', 'product' => 'Product', 'line' => 'Line of business', 'priority' => 'Priority', 'referral_reasons' => 'Referral reasons',
        'risk_details' => 'Risk details', 'item' => 'Item', 'value' => 'Value', 'status' => 'Status', 'carrier' => 'Carrier',
        'policies_total' => 'Policies', 'policies_active' => 'Active policies', 'policy_history' => 'Policies held', 'policy_number' => 'Policy number',
        'coverage_starts_at' => 'Cover start', 'coverage_ends_at' => 'Cover end', 'premium' => 'Premium',
        'claims_total' => 'Claims', 'claims_open' => 'Open claims', 'claims_approved_total' => 'Total approved', 'claims_history' => 'Claims',
        'claim_number' => 'Claim number', 'loss_occurred_at' => 'Loss date', 'estimated_loss' => 'Estimated loss', 'approved_amount' => 'Approved amount',
        'question_set' => 'Question set', 'attested_at' => 'Attested', 'referral_flags' => 'Disclosure referral flags', 'questionnaire' => 'Answers',
        'question' => 'Question', 'answer' => 'Answer', 'required' => 'Required',
        'documents_total' => 'Documents', 'documents_verified' => 'Verified', 'supporting_documents' => 'Required documents', 'requirement' => 'Requirement',
        'document' => 'Document', 'scan' => 'Scan', 'verified_at' => 'Verified', 'notes' => 'Notes',
        'state' => 'State', 'recommendation' => 'System recommendation', 'risk_band' => 'Risk band', 'risk_score' => 'Risk score', 'evaluated_at' => 'Evaluated',
        'engine_evaluation_id' => 'Evaluation', 'rule_set_versions' => 'Rule set versions', 'available_events' => 'Available actions', 'referrals' => 'Referrals',
        'reason_code' => 'Reason', 'severity' => 'Severity', 'due_at' => 'Due', 'resolved_at' => 'Resolved', 'resolution_notes' => 'Resolution notes',
        'factors_fired' => 'Factors fired', 'score_factors' => 'Scoring factors', 'factor' => 'Factor', 'source' => 'Source', 'weight' => 'Weight', 'fired' => 'Fired',
        'contribution' => 'Contribution', 'input' => 'Input',
        'cover_terms' => 'Agreed cover terms', 'decision_conditions' => 'Decision conditions', 'coverage' => 'Offered coverage', 'cover' => 'Cover', 'detail' => 'Detail',
        'tax' => 'Tax', 'fees' => 'Fees', 'total' => 'Total', 'original_premium' => 'Original premium (before override)', 'premium_override' => 'Premium override',
        'tariff_version' => 'Tariff version', 'valid_until' => 'Offer valid until', 'premium_breakdown' => 'Premium calculation', 'component' => 'Component', 'amount' => 'Amount',
        'requested_at' => 'Requested', 'requested_by' => 'Requested by', 'responded_at' => 'Responded', 'message' => 'Message', 'requested_items' => 'Requested items',
        'code' => 'Code', 'kind' => 'Kind', 'description' => 'Description', 'mandatory' => 'Mandatory',
        'inspections' => 'Inspections', 'medical' => 'Medical requirements', 'requirements' => 'Inspection & medical requirements',
        'open_referrals' => 'Open referrals', 'pending_approvals' => 'Pending approvals', 'outcome' => 'Outcome', 'approval_requests' => 'Approval requests',
        'action_code' => 'Action', 'decided_by' => 'Decided by', 'decided_at' => 'Decided', 'reason' => 'Reason', 'decisions' => 'Decision trail',
        'system_recommendation' => 'System recommendation', 'agrees' => 'Agrees with system',
        'decision_due_at' => 'Decision due', 'underwriter' => 'Underwriter', 'avg_hours' => 'Avg. hours', 'approved' => 'Approved', 'declined' => 'Declined', 'agreement' => 'Agreement with system',
    ],
];
