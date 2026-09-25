<?php

return [
    'unknown' => 'Unknown',
    'portals' => ['insurer' => 'Insurer portal', 'broker' => 'Broker portal'],
    'meta' => ['carrier' => 'Carrier', 'premium' => 'Premium', 'cover' => 'Cover', 'version' => 'Version', 'priority' => 'Priority', 'reserve' => 'Reserve', 'assignee' => 'Assignee', 'loss_at' => 'Loss date', 'line' => 'Line', 'created' => 'Created'],
    'tabs' => ['overview' => 'Overview', 'timeline' => 'Timeline', 'documents' => 'Documents', 'financial' => 'Financial', 'authority' => 'Authority'],
    'list' => [
        'empty_heading' => 'Nothing here yet',
        'empty_description' => 'Records appear here as soon as they are created.',
        'no_result_heading' => 'No results match',
        'no_result_description' => 'Clear the search or filters to see all records.',
        'export' => 'Export selected (CSV)',
    ],
    'timeline' => ['heading' => 'Timeline', 'empty' => 'No recorded events yet.', 'system' => 'System', 'actor' => 'Actor', 'role' => 'Role', 'event' => 'Event', 'result' => 'Result', 'reason' => 'Reason', 'channel' => 'Channel', 'document' => 'Document'],
    'documents' => ['heading' => 'Documents', 'empty' => 'No documents issued for this record.', 'withheld' => ':count document(s) hidden: your role cannot view their security level.', 'certificate' => 'Insurance certificate', 'number' => 'Number', 'version' => 'Version', 'status' => 'Status', 'verification' => 'Verification', 'issuer' => 'Issuer', 'issued' => 'Issued', 'expires' => 'Expires', 'replaces' => 'Replaces', 'verify' => 'Verify (QR)'],
    'financial' => ['heading' => 'Financial', 'empty' => 'No financial movements recorded.', 'premium_payment' => 'Premium payment', 'claim_payment' => 'Claim payment', 'amount' => 'Amount', 'status' => 'Status', 'source' => 'Source', 'payer' => 'Payer', 'payee' => 'Payee', 'reference' => 'Reference', 'reconciliation' => 'Reconciliation', 'journal' => 'Journal'],
    'authority' => ['heading' => 'Authority', 'amount' => 'Transaction amount', 'yours' => 'Your authority', 'required' => 'Required authority', 'within' => 'Within your authority', 'referral' => 'Referral required', 'yes' => 'Yes', 'no' => 'No', 'no_rule' => 'No active approval rule: the default maker-checker applies.', 'restricted' => 'Rule details are visible to approval-matrix viewers only.'],
    'metrics' => [
        'active_policies' => 'Active policies', 'pending_issuance' => 'Paid, awaiting issuance', 'open_claims' => 'Open claims',
        'outstanding_reserve' => 'Outstanding reserve', 'underwriting_queue' => 'Underwriting queue', 'failed_claim_payments' => 'Failed claim payments',
        'quotes_30d' => 'Quotes (30 days)', 'proposals_open' => 'Open proposals', 'renewals_due_30d' => 'Renewals due (30 days)', 'payments_pending' => 'Payments pending',
    ],
    'failure' => [
        'PAYMENT_OK_ISSUANCE_FAILED' => ['title' => 'Payment received, policy not issued', 'message' => 'The premium was collected but issuance was rejected. Do not collect again; re-submit issuance or refund.'],
        'ISSUED_DOCUMENT_FAILED' => ['title' => 'Policy issued, document failed', 'message' => 'The policy is active but a document failed to generate. Regenerate it from the document register.'],
        'CLAIM_PAYMENT_FAILED' => ['title' => 'Claim payment failed', 'message' => 'A claim payment was rejected by the payment provider. Check the payee details and retry.'],
        'PROVIDER_SETTLEMENT_FAILED' => ['title' => 'Provider settlement failed', 'message' => 'The settlement to the provider did not complete. It will not be retried automatically.'],
        'CARRIER_API_DOWN' => ['title' => 'Insurer system unavailable', 'message' => 'The insurer integration is not responding. Your request is saved and will be sent when it recovers.'],
        'REINSURANCE_API_DOWN' => ['title' => 'Reinsurer system unavailable', 'message' => 'The reinsurance integration is not responding. Cessions are queued.'],
        'STALE_RECORD' => ['title' => 'This record changed', 'message' => 'Someone else updated this record since you opened it. Reload before saving.'],
        'DUPLICATE_SUBMISSION' => ['title' => 'Already submitted', 'message' => 'This request was already received. It was not processed twice.'],
        'PERMISSION_DENIED' => ['title' => 'Not permitted', 'message' => 'Your role does not allow this action. Ask an administrator for access.'],
        'NETWORK_FAILURE' => ['title' => 'Connection lost', 'message' => 'The request did not reach the server. Nothing was saved; try again.'],
    ],
];
