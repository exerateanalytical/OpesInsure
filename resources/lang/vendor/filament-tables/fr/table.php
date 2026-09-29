<?php

// UI audit 2026-09-27: the French Filament tables pack has no 'result_count', so every FR list page
// showed the raw key "filament-tables::table.result_count". Merged over the package file by Laravel.
// Visual QA 2026-09-27: nor 'columns.icon.boolean' — every FR IconColumn::boolean() rendered the raw key
// "table.columns.icon.boolean.true" as its accessible label (approval matrix, devices, coverages, vehicle masters).
return [
    'columns' => [
        'icon' => [
            'boolean' => [
                'true' => 'Oui',
                'false' => 'Non',
            ],
        ],
    ],
    'result_count' => '{0} Aucun résultat|{1} :count résultat|[2,*] :count résultats',
    // Q10 2026-09-29: nor 'loading' (tables with deferred loading showed "filament-tables::table.loading").
    'loading' => 'Chargement…',
];
