<?php

// Shared scanner for REQ-ARC-008 status-write guard (used by ArchitectureGovernanceTest).
use Illuminate\Support\Facades\File;

if (! function_exists("opesStatusWriteCounts")) {
function opesStatusWriteCounts(): array
{
    $root = realpath(__DIR__.'/../../app/Interfaces/Http');
    $counts = [];
    foreach (File::allFiles($root) as $file) {
        if (! str_ends_with($file->getFilename(), 'Controller.php')) {
            continue;
        }
        $src = $file->getContents();
        $n = preg_match_all('/->status\s*=(?![=>])/', $src);
        $n += preg_match_all('/->(?:update|forceFill|fill)\(\s*\[[^\]]*[\'"]status[\'"]\s*=>/s', $src);
        if ($n > 0) {
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(realpath(__DIR__.'/../..')) + 1));
            $counts[$rel] = $n;
        }
    }
    ksort($counts);

    return $counts;
}
}
