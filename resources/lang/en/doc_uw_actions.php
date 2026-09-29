<?php

// UI coverage batch 11: documents, correspondence, delegated authority, e-signature and underwriter workspace actions.
return [
    'empty' => 'Nothing to show yet',
    'uw_group' => 'Underwriting',

    'nav' => [
        'group' => 'Documents & correspondence',
        'documents' => 'Document register',
        'correspondence' => 'Correspondence register',
        'delegated_authorities' => 'Delegated authorities',
        'signatures' => 'My signatures',
    ],

    'columns' => [
        'reference' => 'Reference', 'direction' => 'Direction', 'channel' => 'Channel', 'counterparty' => 'Counterparty', 'subject' => 'Subject',
        'status' => 'Status', 'proof' => 'Proof', 'created_at' => 'Created', 'category' => 'Category', 'party' => 'Party', 'mime_type' => 'File type',
        'size_bytes' => 'Size (bytes)', 'scan_status' => 'Scan', 'verification_status' => 'Verification', 'agreement_number' => 'Agreement no.',
        'carrier' => 'Carrier', 'partner' => 'Partner', 'effective_from' => 'Effective from', 'effective_until' => 'Effective until',
        'permitted_lines' => 'Permitted lines', 'max_policy_premium' => 'Max policy premium', 'signer_role' => 'Signing as',
        'signing_order' => 'Order', 'provider' => 'Method', 'expires_at' => 'Expires',
    ],

    'fields' => [
        'assignee' => 'Assign to underwriter', 'note' => 'Note (optional)', 'referral' => 'Referral', 'resolution_notes' => 'Resolution notes (at least 20 characters)',
        'direction' => 'Direction', 'channel' => 'Channel', 'counterparty_type' => 'Counterparty type', 'counterparty_name' => 'Counterparty name',
        'counterparty_contact' => 'Counterparty contact', 'case' => 'Case (optional)', 'subject_line' => 'Subject', 'summary' => 'Summary',
        'external_reference' => 'External reference', 'received_at' => 'Received at', 'proof_type' => 'Proof of dispatch',
        'proof_reference' => 'Proof reference (receipt, waybill, message ID)', 'dispatched_at' => 'Dispatched at', 'delivered_at' => 'Delivered at (if known)',
        'delivered' => 'Delivered', 'failure_reason' => 'Failure reason', 'category' => 'Document category', 'storage_key' => 'Storage key',
        'mime_type' => 'File type', 'size_bytes' => 'Size (bytes)', 'sha256' => 'SHA-256 checksum (64 hex characters)', 'party' => 'Party (optional)',
        'scan_status' => 'Scan result', 'verification_status' => 'Verification', 'notes' => 'Review notes', 'purpose' => 'Purpose of access',
        'carrier' => 'Carrier', 'partner' => 'Partner (intermediary)', 'agreement_number' => 'Agreement number', 'effective_from' => 'Effective from',
        'effective_until' => 'Effective until', 'permitted_lines' => 'Permitted lines of business',
        'max_policy_premium_minor' => 'Maximum premium per policy (XAF, minor units)', 'max_claim_authority_minor' => 'Claims settlement authority (XAF, minor units)',
        'territories' => 'Territories', 'reason' => 'Reason', 'line_code' => 'Line of business', 'premium_minor' => 'Premium (XAF, minor units)',
        'territory' => 'Territory', 'effective_at' => 'Cover start', 'consent_text' => 'Consent statement', 'consent_accepted' => 'I have read and accept the consent statement and sign electronically',
    ],

    'codes' => [
        'direction' => ['INBOUND' => 'Inbound', 'OUTBOUND' => 'Outbound'],
        'channel' => ['EMAIL' => 'Email', 'SMS' => 'SMS', 'WHATSAPP' => 'WhatsApp', 'LETTER' => 'Letter', 'COURIER' => 'Courier', 'PORTAL' => 'Portal', 'PHONE' => 'Phone', 'IN_PERSON' => 'In person'],
        'counterparty' => ['CUSTOMER' => 'Customer', 'CARRIER' => 'Insurer', 'BROKER' => 'Broker', 'REGULATOR' => 'Regulator', 'OMBUDSMAN' => 'Ombudsman', 'PROVIDER' => 'Provider', 'LAWYER' => 'Lawyer', 'OTHER' => 'Other'],
        'proof' => ['PROVIDER_RECEIPT' => 'Provider receipt', 'REGISTERED_MAIL' => 'Registered mail', 'COURIER_WAYBILL' => 'Courier waybill',
            'SIGNED_ACKNOWLEDGEMENT' => 'Signed acknowledgement', 'PORTAL_READ_RECEIPT' => 'Portal read receipt', 'EMAIL_MESSAGE_ID' => 'Email message ID'],
        'scan' => ['CLEAN' => 'Clean', 'INFECTED' => 'Infected', 'FAILED' => 'Scan failed'],
        'verification' => ['VERIFIED' => 'Verified', 'REJECTED' => 'Rejected', 'NEEDS_REVIEW' => 'Needs review'],
    ],

    'uwAssign' => ['label' => 'Assign underwriter', 'help' => 'Assigns the case to an underwriter of this organisation.', 'done' => 'Underwriter assigned'],
    'uwStartReview' => ['label' => 'Start review', 'help' => 'Moves the referred case into review; you become the assignee if nobody is assigned.', 'done' => 'Review started'],
    'uwEvaluate' => ['label' => 'Run rules evaluation', 'help' => 'Evaluates the latest submitted proposal against the underwriting rules. The system recommends; an underwriter decides.',
        'done' => 'Evaluation recorded', 'result' => 'Recommendation :recommendation (score :score, band :band)'],
    'uwReadyForDecision' => ['label' => 'Ready for decision', 'help' => 'Marks the review complete. All referrals must be resolved first.', 'done' => 'Case ready for decision'],
    'uwResolveReferral' => ['label' => 'Resolve referral', 'help' => 'Closes an open referral with your resolution notes.', 'done' => 'Referral resolved'],

    'corRegister' => ['label' => 'Register correspondence', 'help' => 'Records an inbound item as received or an outbound item as a draft.', 'done' => 'Correspondence registered'],
    'corDispatch' => ['label' => 'Record dispatch', 'help' => 'Records dispatch with its proof. Proof is write-once.', 'done' => 'Dispatch recorded'],
    'corOutcome' => ['label' => 'Record delivery outcome', 'help' => 'Marks a dispatched item delivered or failed. A failed item must be re-issued as a new entry.', 'done' => 'Outcome recorded'],

    'docRegister' => ['label' => 'Register document', 'help' => 'Registers a stored file. It stays pending until scanned and reviewed.', 'done' => 'Document registered'],
    'docReview' => ['label' => 'Review document', 'help' => 'Records the scan and verification result. An unclean document cannot be verified.', 'done' => 'Document reviewed'],
    'docAccess' => ['label' => 'Open document', 'help' => 'Creates a time-limited link. Every access is logged with its purpose.', 'done' => 'Access logged',
        'link' => 'Link valid for :minutes minutes', 'open' => 'Open'],

    'daCreate' => ['label' => 'New agreement', 'help' => 'Creates a delegated-authority agreement as a draft; a carrier approver must activate it.', 'done' => 'Agreement created as draft',
        'duplicate' => 'This agreement number is already used.'],
    'daApprove' => ['label' => 'Approve agreement', 'help' => 'Activates the draft agreement on behalf of the carrier.', 'done' => 'Agreement activated', 'not_draft' => 'Only a draft agreement can be approved.'],
    'daCheck' => ['label' => 'Check authority', 'help' => 'Checks whether a policy would fall within this agreement. Nothing is recorded.', 'done' => 'Authority checked',
        'within' => 'Within authority (:reason)', 'outside' => 'Outside authority: :reason'],

    'sigSign' => ['label' => 'Sign', 'help' => 'Signs the document electronically. Your identity, the consent statement and the document fingerprint are recorded.', 'done' => 'Document signed'],
    'sigDecline' => ['label' => 'Decline to sign', 'help' => 'Declines the signature request. This closes the request for all signers.', 'done' => 'Signature declined'],
];
