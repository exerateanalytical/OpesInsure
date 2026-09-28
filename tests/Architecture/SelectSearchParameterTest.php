<?php

/**
 * Filament injects a Select's search term by parameter NAME ($search). A closure written as
 * fn (string $s) or fn (string $q) throws "[$s] was unresolvable" as soon as someone types
 * (production log 2026-09-28).
 */
it('names the search term $search in every Select search closure', function () {
    $bad = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
    foreach ($files as $file) {
        if (! str_ends_with((string) $file, '.php')) {
            continue;
        }
        $src = file_get_contents((string) $file);
        if (preg_match_all('/getSearchResultsUsing\(\s*(?:static\s+)?(?:fn|function)\s*\(\s*(?:string\s*)?\$(\w+)/', $src, $m)) {
            foreach ($m[1] as $name) {
                if ($name !== 'search') {
                    $bad[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', (string) $file).' ($'.$name.')';
                }
            }
        }
    }

    expect($bad)->toBe([]);
});
