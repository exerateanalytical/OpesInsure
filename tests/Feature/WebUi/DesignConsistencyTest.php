<?php

declare(strict_types=1);

/*
 * UI design consistency (audit 2026-09-27): static scan of the Filament code.
 *  - Lucide icons only: no Heroicon enum or "heroicon-*" names (LucideIcons, the runtime
 *    Heroicon->Lucide mapper for vendor chrome, is the one allowed reference);
 *  - money columns go through App\Filament\Shared\Columns::money (no inline Money::display closures,
 *    no raw TextColumn on a *_minor attribute).
 */

/** Files allowed to keep Heroicons (none: the sweep is complete). */
const HEROICON_PENDING = [];

function filamentSources(): array
{
    $roots = [app_path('Filament'), app_path('Application/Providers/Workspace/Filament')];
    $files = [];
    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $files[str_replace('\\', '/', substr($f->getPathname(), strlen(base_path()) + 1))] = file_get_contents($f->getPathname());
            }
        }
    }

    return $files;
}

it('uses Lucide icons only (no Heroicons) in the Filament code', function () {
    $offenders = [];
    foreach (filamentSources() as $path => $src) {
        if (str_ends_with($path, 'Filament/Shared/LucideIcons.php') || in_array($path, HEROICON_PENDING, true)) {
            continue;
        }
        if (preg_match_all('/heroicon-[os]-[a-z0-9-]+|Heroicon::[A-Za-z0-9]+|Icons\\\\Heroicon\b/', $src, $m)) {
            $offenders[$path] = array_values(array_unique($m[0]));
        }
    }

    expect($offenders)->toBe([]);
});

it('renders every money column through Columns::money', function () {
    $offenders = [];
    foreach (filamentSources() as $path => $src) {
        if (str_ends_with($path, 'Filament/Shared/Columns.php')) {
            continue;
        }
        // Inline closure formatting a table column with Money::display.
        if (preg_match_all('/TextColumn::make\([^)]*\)(?:(?!TextColumn::make).){0,400}?->formatStateUsing\(\s*(?:static\s+)?fn[^=]*=>\s*\\\\?(?:App\\\\Application\\\\WebExperiences\\\\)?Money::display/s', $src, $m)) {
            $offenders[$path][] = 'inline Money::display closure';
        }
        // A *_minor attribute rendered as a plain TextColumn (raw minor units).
        if (preg_match_all("/TextColumn::make\('([a-z0-9_.]*_minor)'\)/", $src, $m)) {
            array_push($offenders[$path] ??= [], ...$m[1]);
        }
    }

    expect($offenders)->toBe([]);
});
