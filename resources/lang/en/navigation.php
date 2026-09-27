<?php

declare(strict_types=1);

/*
 * Sidebar labels (App\Filament\Shared\LocalizedNavigationManager). Keys are the sentence-case English label,
 * optionally prefixed by the sentence-case group ("Group / Label") when the same label exists in several groups.
 * English only renames entries that were ambiguous (duplicate "Dashboard", "Product mapping", "Document types")
 * or named after the database table instead of the business object.
 */
return [
    'groups' => ['Administration' => 'Administration'], // never empty: an empty array would fall back to the French file
    'labels' => [
        'CIMA regulatory dictionary / Dashboard' => 'CIMA overview',
        'Document engine / Dashboard' => 'Document engine overview',
        'Master data / Dashboard' => 'Master data overview',
        'Document engine / Document types' => 'Document register',
        'Document engine / Product mapping' => 'Product documents',
        'CIMA regulatory dictionary / Product mapping' => 'CIMA product mapping',
        'Integrations / Health' => 'Integration health',
        'Tenant customers' => 'Customers',
        'Payment intent records' => 'Payment requests',
        'Sticker stocks' => 'Sticker stock',
    ],
    'columns' => [
        'number' => 'Number',
        'status' => 'Status',
        'channel' => 'Channel',
        'requested' => 'Requested',
        'due' => 'Due',
        'responded' => 'Responded',
        'reason' => 'Reason',
        'severity' => 'Severity',
        'resolved' => 'Resolved',
        'created' => 'Created',
        'reference' => 'Reference',
        'currency' => 'Currency',
        'settlement_method' => 'Settlement method',
        'effective_from' => 'Effective from',
        'effective_until' => 'Effective until',
        'name' => 'Name',
        'type' => 'Type',
        'year' => 'Underwriting year',
        'ceded_percent' => 'Ceded %',
        'gross_premium' => 'Gross premium',
        'ceded_premium' => 'Ceded premium',
        'net_ceded_premium' => 'Net ceded premium',
        'subject' => 'Subject',
        'level' => 'Level',
        'screening' => 'Screening',
        'submitted' => 'Submitted',
        'expires' => 'Expires',
        'opening_float' => 'Opening float',
        'expected_cash' => 'Expected cash',
        'counted_cash' => 'Counted cash',
        'variance' => 'Variance',
        'opened' => 'Opened',
        'closed' => 'Closed',
        'base_currency' => 'Base currency',
        'quote_currency' => 'Quote currency',
        'rate' => 'Rate',
        'source' => 'Source',
    ],
];
