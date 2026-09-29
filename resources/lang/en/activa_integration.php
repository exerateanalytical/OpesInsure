<?php

// Integrations → Activa Assurances (App\Filament\Admin\Pages\Integrations\ActivaIntegration). Mirror: resources/lang/fr/activa_integration.php.
return [
    'nav' => 'Activa Assurances',
    'title' => 'Activa Assurances Cameroun — API connection',
    'empty' => 'Nothing has been sent to Activa yet.',
    'not_configured' => 'Enter the Activa API subscription key and the service logins with “Credentials”. Nothing is sent to Activa until then; once Activa accepts the credentials, pending policies and payments are synced automatically.',
    'connection' => 'Connection :environment',
    'set' => 'Set',
    'missing' => 'Missing',
    'last_reference_sync' => 'Last reference sync',
    'last_reconciled' => 'Last reconciliation',
    'last_ok' => 'Last success',
    'queue' => 'Sync queue',
    'queue_counts' => ':failures to fix · :synced synced · :calls calls (24h)',
    'circuit_open' => 'Circuit breaker open: calls paused briefly after repeated failures.',
    'secret_help' => 'Write-only. Leave blank to keep the stored value.',
    'status' => [
        'CONFIG_REQUIRED' => 'Configuration required',
        'PENDING_VERIFICATION' => 'Credentials entered, not verified yet',
        'ACTIVE' => 'Active',
        'AUTH_FAILED' => 'Rejected by Activa (401) — waiting for Activa to approve the subscription or for corrected credentials',
        'DISABLED' => 'Disabled',
    ],
    'health' => [
        'OK' => 'OK', 'CONFIG_REQUIRED' => 'Not configured', 'PENDING_VERIFICATION' => 'Not tested yet', 'AUTH_FAILED' => 'Login rejected',
        'SUBSCRIPTION_KEY_REJECTED' => 'Subscription key rejected', 'UNAVAILABLE' => 'Unavailable', 'INVALID_RESPONSE' => 'Unexpected answer',
    ],
    'services' => ['travel' => 'Travel (cmr-travel)', 'pricing' => 'Pricing (Tarifiktor)', 'subscription' => 'Subscription (Souscription CMR)', 'documents' => 'Documents (DocGenerator)'],
    'sections' => [
        'travel' => 'Travel API — OAuth client', 'pricing' => 'Tarifiktor — login', 'subscription' => 'Souscription CMR — login', 'documents' => 'DocGenerator — login',
        'settings' => 'Advanced settings (non-secret)',
    ],
    'fields' => [
        'carrier' => 'Carrier', 'environment' => 'Environment', 'subscription_key' => 'API subscription key (Ocp-Apim-Subscription-Key)',
        'service_subscription_key' => 'Subscription key for this API (only if different)', 'client_id' => 'Client ID', 'client_secret' => 'Client secret', 'scope' => 'Scope',
        'email' => 'Email', 'user_id' => 'User ID', 'password' => 'Password', 'gateway_url' => 'Gateway URL', 'api_version' => 'API version',
        'paths' => 'API paths (service → path)', 'intermediary' => 'Intermediary codes (code_intermediaire, codeinte, bureau, …)', 'categories' => 'Activa category codes (AUTO, MRH, SANTE, IA)',
        'travel' => 'Travel options (agent_scope, category, language)', 'attestation' => 'Attestation (codtypdocument)', 'payment_modes' => 'Payment mode per provider (mtn_momo → code)',
        'disabled' => 'Disable this connection', 'include_failed' => 'Also retry failed / mapping-required items',
    ],
    'columns' => [
        'operation' => 'Step', 'subject_type' => 'Record', 'status' => 'Status', 'attempts' => 'Attempts', 'external_reference' => 'Activa reference',
        'last_error_code' => 'Last error', 'next_attempt_at' => 'Next attempt', 'updated_at' => 'Updated',
    ],
    'configure' => ['label' => 'Credentials', 'help' => 'Stored encrypted. Secrets are never shown again; leave a secret blank to keep it.', 'done' => 'Activa connection saved.'],
    'testConnection' => ['label' => 'Test connection', 'help' => 'Calls each configured service’s authentication and reports OK or the HTTP status (e.g. 401).', 'done' => 'Connection tested.'],
    'referenceSync' => ['label' => 'Run reference sync', 'help' => 'Copies Activa’s referential data (runs daily at 01:30).', 'done' => 'Reference sync finished.',
        'summary' => 'Received :received · new :created · changed :updated · unchanged :unchanged · retired :retired · mapped :mapped :errors'],
    'reconcile' => ['label' => 'Run reconciliation', 'help' => 'Sends issued policies and collected payments that Activa does not hold yet (runs every 10 minutes).', 'done' => 'Reconciliation finished.',
        'summary' => 'Policies :policies · payments :payments · cancelled :cancelled · updated :updated · probed :probed'],
    'retry' => ['label' => 'Retry', 'help' => 'Runs this step again now.', 'done' => 'Retried.'],
];
