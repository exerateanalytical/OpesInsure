<?php

declare(strict_types=1);

/*
 * Sidebar labels (App\Filament\Shared\LocalizedNavigationManager). Keys are the sentence-case English label,
 * optionally prefixed by the sentence-case group ("Group / Label") when the same label exists in several groups.
 * English only renames entries that were ambiguous (duplicate "Dashboard", "Product mapping", "Document types")
 * or named after the database table instead of the business object.
 */
return [
    'groups' => [],
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
];
