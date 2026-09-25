<?php

declare(strict_types=1);

/**
 * REQ-TMP-003 — owner decision 19 (2026-09-25), steps 1-4: read-only affected-row report of timestamptz values written
 * before the offset fix (commit 676908c, release r20260925-034108). No data is modified.
 */

use App\Application\DataCorrections\TimestampOffsetAudit;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

it('REQ-TMP-003: classifies rows by provenance, samples them, proposes (never applies) the shift and changes no data', function () {
    $pre = Party::create(['type' => 'PERSON', 'display_name' => 'Pre fix', 'status' => 'ACTIVE']);
    $touched = Party::create(['type' => 'PERSON', 'display_name' => 'Pre fix, updated after', 'status' => 'ACTIVE']);
    $post = Party::create(['type' => 'PERSON', 'display_name' => 'Post fix', 'status' => 'ACTIVE']);
    DB::table('parties')->where('id', $pre->id)->update(['created_at' => '2026-09-24 10:00:00+00', 'updated_at' => '2026-09-24 10:00:00+00',
        'merged_at' => '2027-01-01 00:00:00+00']);   // a future-dated value written before the fix is still affected
    DB::table('parties')->where('id', $touched->id)->update(['created_at' => '2026-09-24 11:00:00+00', 'updated_at' => '2026-09-25 09:00:00+00',
        'merged_at' => '2026-09-24 11:00:00+00']);
    DB::table('parties')->where('id', $post->id)->update(['created_at' => '2026-09-25 06:00:00+00', 'updated_at' => '2026-09-25 06:00:00+00']);
    $before = DB::table('parties')->orderBy('id')->get()->toJson();

    $r = app(TimestampOffsetAudit::class)->report(null, 5, 'Africa/Douala');
    expect($r['read_only'])->toBeTrue()->and($r['data_modified'])->toBeFalse()
        ->and($r['fix']['release'])->toBe('r20260925-034108')->and($r['fix']['commit'])->toStartWith('676908c')
        ->and($r['fix']['deployed_at_utc'])->toBe('2026-09-25T03:41:08+00:00')->and($r['defect']['offset_seconds'])->toBe(3600);

    $cols = collect($r['columns'])->where('table', 'parties')->keyBy('column');
    expect($cols['created_at']['affected'])->toBe(2)->and($cols['updated_at']['affected'])->toBe(1)
        ->and($cols['merged_at']['affected'])->toBe(1)->and($cols['merged_at']['ambiguous'])->toBe(1)
        ->and($cols['merged_at']['row_provenance_basis'])->toBe('ROW_CREATED_AND_LAST_UPDATED_BEFORE_FIX');
    $sample = collect($cols['merged_at']['samples'])->firstWhere('classification', 'AFFECTED');
    expect($sample['primary_key'])->toBe(['pk_id' => $pre->id])->and($sample['stored_utc'])->toBe('2027-01-01T00:00:00+00:00')
        ->and($sample['proposed_true_utc_not_applied'])->toBe('2026-12-31T23:00:00+00:00');

    expect(DB::table('parties')->orderBy('id')->get()->toJson())->toBe($before);   // nothing changed
})->group('REQ-TMP-003');

it('REQ-TMP-003: the artisan command writes JSON and CSV under storage/app/reports', function () {
    $dir = storage_path('app/reports');
    $existing = File::isDirectory($dir) ? File::files($dir) : [];
    $this->artisan('opesinsure:timestamps:affected-report', ['--samples' => 1])->assertSuccessful();
    $new = collect(File::files($dir))->reject(fn ($f) => in_array($f->getPathname(), array_map(fn ($e) => $e->getPathname(), $existing), true));
    expect($new->map(fn ($f) => $f->getExtension())->sort()->values()->all())->toBe(['csv', 'json']);
    $json = json_decode(File::get($new->first(fn ($f) => $f->getExtension() === 'json')->getPathname()), true);
    expect($json['report'])->toBe('timestamp_offset_affected_rows')->and($json['next_steps_require_owner'])->toHaveCount(6);
    $new->each(fn ($f) => File::delete($f->getPathname()));
})->group('REQ-TMP-003');
