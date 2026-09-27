<?php

// UI audit 2026-09-27: the French Filament panels pack has no 'skip_to_content', so every FR panel page
// rendered the raw key "filament-panels::layout.skip_to_content.label" in its skip link. Merged over the package file.
return [
    'skip_to_content' => [
        'label' => 'Aller au contenu',
    ],
];
