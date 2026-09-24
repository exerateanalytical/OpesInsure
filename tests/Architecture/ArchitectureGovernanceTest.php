<?php

/**
 * Architecture governance guards.
 *
 * @covers REQ-ARC-008 REQ-TST-001 REQ-DUP-019 REQ-ARC-005
 * ADRs: docs/adr/ADR-001..ADR-005
 */

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

require_once __DIR__."/status_writes.php";

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);


test('REQ-ARC-007 domain layer never reads the wall clock directly', function () {
    $offenders = [];
    foreach (File::allFiles(realpath(__DIR__.'/../../app/Domain')) as $file) {
        if (str_starts_with(str_replace("\\", "/", $file->getRelativePathname()), "Shared/Clock/")) {
            continue; // the Clock abstraction itself is the only allowed wall-clock reader
        }
        if (preg_match('/(?<![\w>$:])now\(\)|Carbon(?:Immutable)?::now\(|Date::now\(/', $file->getContents())) {
            $offenders[] = $file->getRelativePathname();
        }
    }
    expect($offenders)->toBe([], 'Inject a clock / pass $asOf instead of calling now() in app/Domain');
});

test('REQ-ARC-008 controllers do not add new direct status writes (baseline allowlist)', function () {
    $baseline = json_decode(file_get_contents(__DIR__.'/status_write_allowlist.json'), true)['files'];
    $new = [];
    foreach (opesStatusWriteCounts() as $file => $n) {
        if ($n > ($baseline[$file] ?? 0)) {
            $new[] = "$file ($n > ".($baseline[$file] ?? 0).')';
        }
    }
    expect($new)->toBe([], 'Status transitions belong in an Application service/state machine, not controllers. Do not grow tests/Architecture/status_write_allowlist.json.');
});

test('REQ-DUP-019 doubly declared tables have exactly one documented owner migration', function () {
    $owners = json_decode(file_get_contents(__DIR__.'/table_owners.json'), true)['tables'];
    $declared = [];
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $m) {
        preg_match_all("/Schema::create\(\s*'([a-z0-9_]+)'/", file_get_contents($m), $mm);
        foreach (array_unique($mm[1]) as $t) {
            $declared[$t][] = basename($m, '.php');
        }
    }
    $dupes = array_filter($declared, fn ($ms) => count($ms) > 1);
    $undocumented = array_diff(array_keys($dupes), array_keys($owners));
    expect(array_values($undocumented))->toBe([], 'New table declared in two migrations: add a single owner (ADR-005) or remove the duplicate');
    foreach ($owners as $table => $owner) {
        expect($declared[$table] ?? [])->toContain($owner);
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

test('REQ-ARC-005 architecture decision records exist', function () {
    foreach (['ADR-001', 'ADR-002', 'ADR-003', 'ADR-004', 'ADR-005'] as $id) {
        expect(glob(__DIR__."/../../docs/adr/$id-*.md"))->not->toBeEmpty();
    }
});
