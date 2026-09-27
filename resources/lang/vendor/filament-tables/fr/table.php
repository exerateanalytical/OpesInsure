<?php

// UI audit 2026-09-27: the French Filament tables pack has no 'result_count', so every FR list page
// showed the raw key "filament-tables::table.result_count". Merged over the package file by Laravel.
return [
    'result_count' => '{0} Aucun résultat|{1} :count résultat|[2,*] :count résultats',
];
