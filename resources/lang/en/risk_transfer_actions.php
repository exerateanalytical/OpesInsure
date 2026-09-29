<?php

// Risk-transfer workbench (UI coverage batches 20, 25, 27): reinsurance, co-insurance, accumulation, catastrophe events,
// legal matters, developer portal, carrier connectors, capability profiles, compliance catalogue, insurance checks.
return [
    'empty' => 'Nothing to show yet.',
    'checks_empty' => 'Run a check with the buttons above; results are shown once and are not stored on this page.',

    'nav' => [
        'reinsurers' => 'Reinsurers', 'treaties' => 'Treaty workbench', 'facultative' => 'Facultative placements', 'recoveries' => 'Reinsurance recoveries',
        'coinsurance' => 'Co-insurance arrangements', 'legal_matters' => 'Legal matters', 'developer_keys' => 'API keys', 'developer_consents' => 'API consents',
        'carrier_connectors' => 'Carrier connectors', 'accumulation' => 'Accumulation and capacity', 'catastrophe_events' => 'Catastrophe events',
        'capability_profiles' => 'Capability profiles', 'compliance_controls' => 'Compliance controls', 'insurance_checks' => 'Insurance checks',
    ],

    // reinsurance
    'reinsurerCreate' => ['label' => 'New reinsurer', 'help' => 'Starts as pending verification; approved-security status is decided separately.', 'done' => 'Reinsurer created.'],
    'reinsurerStatus' => ['label' => 'Change status', 'done' => 'Reinsurer status changed.'],
    'reinsurerSecurity' => ['label' => 'Approved-security decision', 'help' => 'CIMA_APPROVED needs the source URL of the regulator list.', 'done' => 'Approved-security status recorded.'],
    'treatyCreate' => ['label' => 'New treaty', 'done' => 'Treaty created as draft.'],
    'treatyAddVersion' => ['label' => 'Add version', 'help' => 'Terms and participants of a new draft version; shares must total 100% before activation.', 'done' => 'Treaty version added.'],
    'treatyActivate' => ['label' => 'Activate version', 'help' => 'Four eyes: the creator of a version cannot activate it.', 'done' => 'Treaty version activated.'],
    'treatyThreshold' => ['label' => 'Large-loss threshold', 'done' => 'Large-loss threshold saved.'],
    'cessionPreview' => ['label' => 'Preview cession', 'done' => 'Cession preview computed.'],
    'cessionCede' => ['label' => 'Cede policy', 'help' => 'Calculates and records the treaty cession of the policy (replayed if already ceded).', 'done' => 'Policy ceded.'],
    'facCreate' => ['label' => 'New facultative slip', 'done' => 'Facultative slip created.'],
    'facLines' => ['label' => 'Record written lines', 'done' => 'Written lines recorded.'],
    'facSubmit' => ['label' => 'Sign and submit', 'done' => 'Slip submitted for approval.'],
    'facApprove' => ['label' => 'Approve slip', 'help' => 'Requires facultative authority; the maker cannot approve.', 'done' => 'Slip approved and bound.'],
    'facReject' => ['label' => 'Reject slip', 'done' => 'Slip rejected.'],
    'recoveryEstimate' => ['label' => 'Estimate recovery', 'done' => 'Recovery estimated.'],
    'recoveryNotify' => ['label' => 'Notify reinsurers', 'done' => 'Reinsurers notified.'],
    'recoveryAgree' => ['label' => 'Agree recovery', 'done' => 'Recovery agreed.'],
    'recoveryBill' => ['label' => 'Bill recovery', 'done' => 'Recovery billed.'],

    // co-insurance
    'coCreate' => ['label' => 'New arrangement', 'help' => 'Shares are in basis points and must total 10,000 unless partial placement is allowed.', 'done' => 'Arrangement created as draft.'],
    'coActivate' => ['label' => 'Activate', 'help' => 'Four eyes: the creator cannot activate.', 'done' => 'Arrangement activated.'],
    'coPreview' => ['label' => 'Preview split', 'done' => 'Split computed.'],
    'coApportion' => ['label' => 'Apportion amount', 'done' => 'Amount apportioned.'],
    'coTerminate' => ['label' => 'Terminate', 'done' => 'Arrangement terminated.'],

    // legal
    'legalOpen' => ['label' => 'Open legal matter', 'done' => 'Legal matter opened.'],
    'legalHearing' => ['label' => 'Schedule hearing', 'done' => 'Hearing scheduled.'],
    'legalDeadline' => ['label' => 'Add deadline', 'done' => 'Deadline added.'],
    'legalCost' => ['label' => 'Add cost', 'done' => 'Cost recorded.'],
    'legalOutcome' => ['label' => 'Record outcome', 'done' => 'Outcome recorded; matter concluded.'],

    // developer portal
    'devIssueKey' => ['label' => 'Issue key', 'help' => 'The client secret is shown once only. Copy it now; it cannot be displayed again.', 'done' => 'Key issued.',
        'secret_title' => 'Copy the client secret now — it will not be shown again',
        'secret_body' => 'Client ID: :client_id | Client secret: :secret'],
    'devRevokeKey' => ['label' => 'Revoke key', 'done' => 'Key revoked; its tokens are revoked too.'],
    'devRateLimits' => ['label' => 'Rate limits', 'done' => 'Rate limits saved.'],
    'devGrantConsent' => ['label' => 'Grant consent', 'help' => 'Consent cannot exceed the connection\'s own scopes; a new consent supersedes the previous one.', 'done' => 'Consent granted.'],
    'devRevokeConsent' => ['label' => 'Revoke consent', 'done' => 'Consent revoked.'],

    // carrier connectors
    'connectorConfigure' => ['label' => 'Configure connector', 'help' => 'An API connector needs an HTTPS base URL and an active signing key id.', 'done' => 'Connector saved.'],
    'connectorSync' => ['label' => 'Sync record', 'done' => 'Record mapping synchronised.'],
    'connectorDispatch' => ['label' => 'Dispatch', 'done' => 'Dispatch attempted.'],
    'connectorResolveFallback' => ['label' => 'Resolve fallback', 'done' => 'Fallback resolved.'],
    'connectorResolveConflict' => ['label' => 'Resolve conflict', 'done' => 'Conflict resolved.'],

    // accumulation / catastrophe
    'zoneCreate' => ['label' => 'New zone', 'done' => 'Zone created.'],
    'locationsRebuild' => ['label' => 'Rebuild risk locations', 'done' => 'Risk locations rebuilt.'],
    'snapshotTake' => ['label' => 'Take snapshot', 'done' => 'Exposure snapshot taken.'],
    'capacitySetLimit' => ['label' => 'Set capacity limit', 'done' => 'Capacity limit saved.'],
    'catDeclare' => ['label' => 'Declare event', 'done' => 'Catastrophe event declared.'],
    'catLinkClaim' => ['label' => 'Link claim', 'done' => 'Claim linked to the event.'],
    'catAggregate' => ['label' => 'Aggregate losses', 'done' => 'Event losses aggregated.'],
    'catClose' => ['label' => 'Close event', 'done' => 'Event closed.'],

    // capability profiles
    'capabilityReplaceModes' => ['label' => 'Edit modes', 'done' => 'Modes replaced.'],
    'capabilitySubmit' => ['label' => 'Submit', 'done' => 'Profile submitted for approval.'],
    'capabilityApprove' => ['label' => 'Approve', 'help' => 'Four eyes: the maker or submitter cannot approve.', 'done' => 'Profile approved and active.'],
    'capabilityReject' => ['label' => 'Reject', 'done' => 'Profile rejected.'],

    // compliance catalogue
    'controlAssess' => ['label' => 'Assess control', 'help' => 'A rated status needs a verified requirement text.', 'done' => 'Assessment recorded.'],
    'indicatorFlag' => ['label' => 'Flag fraud indicator', 'help' => 'Opens a review alert; an indicator is never a fraud determination.', 'done' => 'Indicator flagged for review.'],
    'refreshConfigure' => ['label' => 'Configure KYC refresh', 'done' => 'Refresh policy configured; awaiting approval.'],
    'refreshApprove' => ['label' => 'Approve KYC refresh', 'help' => 'Four eyes: the configuring user cannot approve.', 'done' => 'Refresh policy approved.'],

    // insurance checks
    'checkEligibility' => ['label' => 'Check eligibility', 'done' => 'Eligibility evaluated.'],
    'checkCompleteness' => ['label' => 'Check completeness', 'done' => 'Completeness evaluated.'],
    'checkRate' => ['label' => 'Rate a quote', 'done' => 'Tariff preview computed.', 'none' => 'No active tariff for this quote'],

    'fields' => [
        'allow_partial_placement' => 'Allow partial placement', 'amount_minor' => 'Amount (minor units)', 'approved_security_status' => 'Approved-security status',
        'base_backoff_seconds' => 'Base back-off (seconds)', 'base_url' => 'Base URL', 'basis' => 'Basis', 'bordereau_frequency' => 'Bordereau frequency', 'broker' => 'Reinsurance broker',
        'brokerage_percent' => 'Brokerage %', 'capability' => 'Capability', 'carrier' => 'Carrier', 'cession_percent' => 'Cession %', 'claim' => 'Claim',
        'claimed_amount_minor' => 'Amount claimed (minor units)', 'code' => 'Code', 'commission_percent' => 'Commission %', 'cost_type' => 'Cost type', 'country_code' => 'Country (ISO)',
        'court' => 'Court', 'court_reference' => 'Court reference', 'currency' => 'Currency', 'description' => 'Description', 'due_at' => 'Due', 'effective_from' => 'Effective from',
        'effective_to' => 'Effective to', 'effective_until' => 'Effective until', 'endpoints' => 'Endpoints', 'ends_at' => 'Ends', 'environment' => 'Environment', 'evidence' => 'Evidence',
        'expires_at' => 'Expires', 'external_record_id' => 'External record ID', 'external_reference' => 'External reference', 'external_version' => 'External version',
        'facts' => 'Facts', 'fallback_mode' => 'Fallback mode', 'fields' => 'Fields', 'geography_codes' => 'Geography codes', 'gross_limit_minor' => 'Gross limit (minor units)',
        'incurred_on' => 'Incurred on', 'indicator' => 'Indicator', 'integration_client' => 'API connection', 'is_lead' => 'Lead', 'jurisdiction' => 'Jurisdiction (ISO)',
        'label' => 'Label', 'last_external_modified_at' => 'Last modified externally', 'lead_rights' => 'Lead rights', 'legal_role' => 'Our role', 'license_reference' => 'Licence reference',
        'line_code' => 'Line of business', 'lines' => 'Lines', 'lines_written' => 'Written lines', 'location' => 'Location', 'mapping' => 'Record mapping', 'max_attempts' => 'Max attempts',
        'mode' => 'Mode', 'modes' => 'Modes', 'name' => 'Name', 'note' => 'Note', 'notes' => 'Notes', 'offered_percent' => 'Offered %', 'operation' => 'Operation',
        'opesinsure_record_id' => 'OpesInsure record ID', 'opposing_party_name' => 'Opposing party', 'outcome' => 'Outcome', 'participants' => 'Participants', 'peril_code' => 'Peril',
        'period_from' => 'Period from', 'period_to' => 'Period to', 'placed_share_percent' => 'Placed share %', 'policy' => 'Policy', 'premium_minor' => 'Premium (minor units)',
        'product' => 'Product', 'purpose' => 'Purpose', 'quote' => 'Quote', 'rate_limit_per_minute' => 'Production limit / minute', 'rating' => 'Rating', 'rating_agency' => 'Rating agency',
        'reason' => 'Reason', 'record_type' => 'Record type', 'reference' => 'Reference', 'reference_date' => 'Reference date', 'refresh_months' => 'Refresh (months)',
        'refresh_policy' => 'Pending refresh policy', 'regulator' => 'Regulator', 'reinsurer' => 'Reinsurer', 'resolution' => 'Resolution', 'retention_limit_minor' => 'Retention limit (minor units)',
        'retention_minor' => 'Retention (minor units)', 'risk_description' => 'Risk description', 'risk_level' => 'Risk level', 'role' => 'Role',
        'sandbox_rate_limit_per_minute' => 'Sandbox limit / minute', 'scheduled_at' => 'Scheduled for', 'scope_class_code' => 'Class scope', 'scopes' => 'Scopes',
        'settlement_method' => 'Settlement method', 'share_bps' => 'Share (basis points)', 'share_percent' => 'Share %', 'signing_key_id' => 'Signing key ID', 'source_id' => 'Source ID',
        'source_policy_id' => 'Source policy reference', 'source_type' => 'Source type', 'source_url' => 'Source URL', 'starts_at' => 'Starts', 'status' => 'Status',
        'subject_id' => 'Subject ID', 'subject_type' => 'Subject type', 'sum_insured_minor' => 'Sum insured (minor units)', 'tax_percent' => 'Tax %',
        'threshold_minor' => 'Threshold (minor units)', 'threshold_blank' => 'Leave blank to remove the threshold.', 'timeout_seconds' => 'Timeout (seconds)', 'title' => 'Title',
        'total_minor' => 'Total (minor units)', 'transport' => 'Transport', 'treaty_number' => 'Treaty number', 'treaty_type' => 'Treaty type', 'trigger_events' => 'Trigger events',
        'underwriting_year' => 'Underwriting year', 'version' => 'Draft version', 'website' => 'Website', 'written_percent' => 'Written %', 'zone' => 'Zone', 'zones' => 'Zones',
    ],

    'columns' => [
        'agreed_minor' => 'Agreed', 'approved_security_status' => 'Approved security', 'attempt_count' => 'Attempts', 'billed_minor' => 'Billed', 'carrier' => ['trade_name' => 'Carrier'],
        'claimed_amount_minor' => 'Claimed', 'client_name' => 'Connection', 'code' => 'Code', 'control_code' => 'Control', 'correlation_id' => 'Correlation', 'country_code' => 'Country',
        'court' => 'Court', 'court_reference' => 'Court reference', 'created_at' => 'Created', 'currency' => 'Currency', 'current_status' => 'Current status', 'domain' => 'Domain',
        'effective_from' => 'Effective from', 'effective_until' => 'Effective until', 'environment' => 'Environment', 'expires_at' => 'Expires', 'fallback_reason' => 'Fallback reason',
        'framework' => 'Framework', 'geography_codes' => 'Geography', 'granted_at' => 'Granted', 'gross_loss_minor' => 'Gross loss', 'label' => 'Label',
        'large_loss_threshold_minor' => 'Large-loss threshold', 'last_used_at' => 'Last used', 'maturity_code' => 'Maturity', 'message_type' => 'Message type', 'name' => 'Name',
        'opposing_party_name' => 'Opposing party', 'outcome' => 'Outcome', 'peril_code' => 'Peril', 'placed_share_percent' => 'Placed %', 'rating' => 'Rating',
        'recoverable_minor' => 'Recoverable', 'reference' => 'Reference', 'requirement_summary' => 'Requirement', 'revoked_at' => 'Revoked', 'risk_description' => 'Risk',
        'role' => 'Role', 'scopes' => 'Scopes', 'settled_minor' => 'Settled', 'settlement_method' => 'Settlement method', 'starts_at' => 'Starts', 'status' => 'Status',
        'sum_insured_minor' => 'Sum insured', 'treaty_type' => 'Treaty type', 'underwriting_year' => 'Underwriting year', 'updated_at' => 'Updated', 'version' => 'Version',
    ],
];
