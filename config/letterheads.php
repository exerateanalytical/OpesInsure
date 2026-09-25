<?php

return [
    // Disk for logo / header artwork (private; a logo is streamed by the public route only when marked public-display).
    'disk' => env('LETTERHEADS_DISK', env('POLICY_DOCUMENTS_DISK', 'local')),
    // Optional maker-checker: a new version waits for a second admin before it is used on documents.
    'maker_checker' => (bool) env('LETTERHEADS_MAKER_CHECKER', false),
    'logo' => ['max_kb' => 512, 'min_width' => 64, 'min_height' => 32, 'max_width' => 4000, 'max_height' => 4000],
    'header' => ['max_kb' => 1024, 'min_width' => 600, 'min_height' => 60, 'max_width' => 6000, 'max_height' => 2000],
];
