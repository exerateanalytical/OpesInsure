<?php

declare(strict_types=1);

// Support desk, complaints, notifications and security-centre actions (App\Filament\Shared\Actions\SupportActions,
// AccountSecurityActions, SecurityFindingResource) and the /account portal account-security / support additions.
return [
    'case_group' => 'Case actions',
    'complaint_group' => 'Complaint',

    'caseLinkLegacy' => ['label' => 'Link a legacy work item', 'help' => 'Brings an underwriting case, referral task, compliance case or support ticket onto the case engine. Complaint tickets become complaints.', 'done' => 'Work item linked to a case'],
    'caseDecide' => ['label' => 'Record a decision', 'help' => 'Decisions are permanent; to change one, record a new decision that reverses it.', 'done' => 'Decision recorded'],
    'caseDiary' => ['label' => 'Add a diary entry', 'help' => 'Notes, calls, meetings and follow-ups are kept on the case journal.', 'done' => 'Diary entry added'],
    'caseReclassify' => ['label' => 'Change sub-type', 'help' => 'Changing the sub-type may retarget the SLA clocks.', 'done' => 'Case reclassified'],
    'caseAddTask' => ['label' => 'Add a task', 'help' => 'Adds a task to this open case.', 'done' => 'Task added'],
    'caseTaskTransition' => ['label' => 'Update a task', 'help' => 'Moves one of the open tasks of this case.', 'done' => 'Task updated'],

    'complaintSubmit' => ['label' => 'Register a complaint', 'help' => 'Opens a COMPLAINT case and records the complaint as inbound correspondence (proof of receipt).', 'done' => 'Complaint registered'],
    'complaintFromTicket' => ['label' => 'Handle as a complaint', 'help' => 'Consolidates this complaint ticket onto a COMPLAINT case; the ticket then mirrors the case status.', 'done' => 'Ticket consolidated as a complaint'],
    'complaintAcknowledge' => ['label' => 'Acknowledge', 'help' => 'Records that the complainant has been acknowledged.', 'done' => 'Complaint acknowledged'],
    'complaintClassify' => ['label' => 'Classify', 'help' => 'The severity sets the case priority; a regulatory complaint becomes the REGULATORY sub-type.', 'done' => 'Complaint classified'],
    'complaintAssign' => ['label' => 'Assign investigator', 'help' => 'The investigator must be an active member of this organisation.', 'done' => 'Investigator assigned'],
    'complaintInvestigate' => ['label' => 'Start investigation', 'help' => 'Moves the complaint into investigation.', 'done' => 'Investigation started'],
    'complaintResolution' => ['label' => 'Propose resolution', 'help' => 'Records the resolution decision on the case.', 'done' => 'Resolution proposed'],
    'complaintCommunicate' => ['label' => 'Record final response', 'help' => 'Choose the outbound response already registered and dispatched with proof on this case.', 'done' => 'Final response recorded'],
    'complaintEscalate' => ['label' => 'Escalate', 'help' => 'Escalation to the national authority or to CIMA.', 'done' => 'Complaint escalated'],
    'complaintAdvance' => ['label' => 'Move complaint', 'help' => 'Request information, record it as received, reinvestigate or close.', 'done' => 'Complaint updated'],

    'ticketOpen' => ['label' => 'Open a ticket', 'help' => 'Opens a support ticket; the SLA follows the priority.', 'done' => 'Ticket opened'],
    'ticketTransition' => ['label' => 'Change ticket status', 'help' => 'Only the moves allowed by the ticket lifecycle are accepted.', 'done' => 'Ticket status changed'],

    'notificationQueue' => ['label' => 'Send a notification', 'help' => 'Queues a message from an active template. The customer\'s channel preferences are respected.', 'done' => 'Notification queued'],
    'notificationRetry' => ['label' => 'Retry delivery', 'help' => 'Queues another delivery attempt. Past the attempt limit the message is dead-lettered.', 'done' => 'Retry queued'],
    'notificationCancel' => ['label' => 'Cancel delivery', 'help' => 'Stops a queued or failed message from being sent.', 'done' => 'Delivery cancelled'],

    'findingReport' => ['label' => 'Report a finding', 'help' => 'Records a security finding in the security centre register.', 'done' => 'Finding reported'],
    'findingTransition' => ['label' => 'Change finding status', 'help' => 'Accepting a risk needs a justification, a future expiry date and a person other than the reporter or owner.', 'done' => 'Finding updated',
        'accept_risk_denied' => 'Accepting a security risk needs the security.findings.accept_risk permission.'],

    'nav' => ['security_findings' => 'Security findings', 'security_finding' => 'security finding'],

    'columns' => ['reference' => 'Reference', 'title' => 'Title', 'severity' => 'Severity', 'source' => 'Source'],

    'fields' => [
        'source' => 'Source', 'source_id' => 'Source record ID', 'decision_type' => 'Decision type', 'outcome' => 'Outcome', 'rationale' => 'Rationale',
        'conditions' => 'Conditions', 'reverses_decision' => 'Reverses decision', 'entry_type' => 'Entry type', 'body' => 'Text', 'follow_up_at' => 'Follow up on',
        'visibility' => 'Visibility', 'case_subtype' => 'Sub-type', 'reason' => 'Reason', 'title' => 'Title', 'task_type' => 'Task type', 'assignee' => 'Assignee',
        'due_at' => 'Due', 'task' => 'Task', 'task_status' => 'New status', 'complainant_name' => 'Complainant', 'complainant_contact' => 'Complainant contact',
        'channel' => 'Channel', 'description' => 'Description', 'regulatory' => 'Regulatory complaint', 'received_at' => 'Received on', 'category' => 'Category',
        'severity' => 'Severity', 'investigator' => 'Investigator', 'resolution_summary' => 'Resolution summary', 'root_cause' => 'Root cause',
        'redress_amount' => 'Redress amount (XAF)', 'resolution_reason' => 'Resolution reason', 'correspondence' => 'Final response', 'level' => 'Level',
        'reference' => 'External reference', 'event' => 'Step', 'customer' => 'Customer', 'ticket_type' => 'Ticket type', 'priority' => 'Priority',
        'subject' => 'Subject', 'to_status' => 'New status', 'message' => 'Message', 'template' => 'Template', 'destination' => 'Destination (phone or email)',
        'variables' => 'Template variables', 'affected_asset' => 'Affected asset', 'cve' => 'CVE', 'owner' => 'Owner', 'notes' => 'Notes',
        'remediation_plan' => 'Remediation plan', 'risk_acceptance_expires_at' => 'Risk acceptance expires on',
    ],

    'sources' => ['underwriting_cases' => 'Underwriting case', 'underwriting_referral_tasks' => 'Underwriting referral task', 'compliance_cases' => 'Compliance case', 'support_tickets' => 'Support ticket'],
    'entry_types' => ['NOTE' => 'Note', 'CALL' => 'Call', 'MEETING' => 'Meeting', 'FOLLOW_UP' => 'Follow-up'],
    'visibility' => ['INTERNAL' => 'Internal', 'SHARED' => 'Shared'],
    'task_statuses' => ['IN_PROGRESS' => 'In progress', 'BLOCKED' => 'Blocked', 'DONE' => 'Done', 'CANCELLED' => 'Cancelled'],
    'channels' => ['EMAIL' => 'Email', 'SMS' => 'SMS', 'WHATSAPP' => 'WhatsApp', 'LETTER' => 'Letter', 'COURIER' => 'Courier', 'PORTAL' => 'Portal', 'PHONE' => 'Phone', 'IN_PERSON' => 'In person'],
    'severities' => ['INFO' => 'Information', 'LOW' => 'Low', 'MEDIUM' => 'Medium', 'HIGH' => 'High', 'CRITICAL' => 'Critical'],
    'outcomes' => ['UPHELD' => 'Upheld', 'PARTIALLY_UPHELD' => 'Partially upheld', 'NOT_UPHELD' => 'Not upheld'],
    'levels' => ['NATIONAL' => 'National authority', 'CIMA' => 'CIMA'],
    'events' => ['request_info' => 'Request information from the complainant', 'info_received' => 'Information received', 'reinvestigate' => 'Reinvestigate', 'close' => 'Close'],
    'ticket_types' => ['SUPPORT' => 'Support', 'COMPLAINT' => 'Complaint', 'REGULATORY_COMPLAINT' => 'Regulatory complaint'],
    'priorities' => ['LOW' => 'Low', 'NORMAL' => 'Normal', 'HIGH' => 'High', 'URGENT' => 'Urgent'],
    'ticket_statuses' => ['TRIAGED' => 'Triaged', 'IN_PROGRESS' => 'In progress', 'WAITING_CUSTOMER' => 'Waiting for customer', 'ESCALATED' => 'Escalated',
        'RESOLVED' => 'Resolved', 'CLOSED' => 'Closed', 'REOPENED' => 'Reopened', 'CANCELLED' => 'Cancelled'],
    'finding_sources' => ['PENTEST' => 'Penetration test', 'SAST' => 'Static analysis', 'DAST' => 'Dynamic analysis', 'DEPENDENCY_SCAN' => 'Dependency scan',
        'BUG_BOUNTY' => 'Bug bounty', 'INTERNAL_REVIEW' => 'Internal review', 'INCIDENT' => 'Incident', 'AUDIT' => 'Audit', 'MOBILE_MASVS' => 'Mobile (MASVS)'],
    'finding_statuses' => ['OPEN' => 'Reopen', 'TRIAGED' => 'Triaged', 'IN_REMEDIATION' => 'In remediation', 'RESOLVED' => 'Resolved', 'RISK_ACCEPTED' => 'Risk accepted', 'FALSE_POSITIVE' => 'False positive'],

    // /account portal (customer web).
    'portal' => [
        'escalate' => 'Escalate this request', 'escalate_reason' => 'Why is this urgent? (optional)', 'escalated' => 'Your request has been escalated. We will get back to you within 4 hours.',
        'issue_title' => 'Report a problem with this site', 'issue_text' => 'Something not working? Tell us what happened on this page.', 'issue_note' => 'What went wrong?',
        'issue_send' => 'Send report', 'issue_done' => 'Thank you, your report has been sent.',
        'security_title' => 'Sign-in and security',
        'email_verify' => 'Send email confirmation link', 'email_sent' => 'We sent a confirmation link to your email address.', 'email_already' => 'Your email address is already confirmed.',
        'email_none' => 'Add an email address to your profile first.', 'email_off' => 'Email is not available right now. Please try again later.',
        'phone_verify' => 'Send a verification code to my phone', 'phone_code' => 'Code received', 'phone_confirm' => 'Confirm phone number',
        'phone_done' => 'Your phone number is verified.', 'phone_already' => 'Your phone number is already verified.', 'phone_sent' => 'We sent you a 6-digit code.',
        'mfa_title' => 'Two-step verification (authenticator app)', 'mfa_start' => 'Set up an authenticator app',
        'mfa_secret' => 'Add this key to your authenticator app, then enter the 6-digit code it shows:', 'mfa_code' => '6-digit code', 'mfa_confirm' => 'Turn on two-step verification',
        'mfa_done' => 'Two-step verification is on. Keep these recovery codes somewhere safe:',
        'password_title' => 'Change password', 'password_current' => 'Current password', 'password_new' => 'New password (at least 8 characters)',
        'password_confirm' => 'Repeat the new password', 'password_save' => 'Change password', 'password_mismatch' => 'The two new passwords do not match.',
        'password_done' => 'Password changed. For your security you have been signed out everywhere; please sign in again.',
        'invite_title' => 'Join an organisation', 'invite_text' => 'Paste the invitation code you received to join your broker or insurer team.',
        'invite_token' => 'Invitation code', 'invite_accept' => 'Accept invitation', 'invite_done' => 'Invitation accepted. You now belong to :tenant.',
    ],
];
