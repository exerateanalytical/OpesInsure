<?php

declare(strict_types=1);

use App\Application\DocumentCatalogue\CanonicalDocumentSpec;
use App\Application\DocumentCatalogue\DetailedFieldSourceMap;
use App\Application\Documents\Engine\DocumentPackResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 | D2 (DOCUMENT_SECURITY_COMPLETION_PLAN): the 17 canonical spec records without a catalogue type get one,
 | all 220 specs link to a type, and the detailed field rules are mapped to platform sources
 | (REQ-DOC-CANON-D2-*).
 */

const D2_SPEC_IDS = ['DOC-014', 'DOC-015', 'DOC-138', 'DOC-139', 'DOC-140', 'DOC-169', 'DOC-179', 'DOC-185', 'DOC-196', 'DOC-199', 'DOC-200', 'DOC-209', 'DOC-213', 'DOC-214', 'DOC-218', 'DOC-219', 'DOC-220'];

beforeEach(function () {
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
});

it('REQ-DOC-CANON-D2-001: all 220 canonical specs link to a catalogue type (220/220)', function () {
    expect(DB::table('document_canonical_specs')->count())->toBe(220)
        ->and(DB::table('document_canonical_specs')->where('mapping_status', '!=', 'MAPPED')->count())->toBe(0)
        ->and(DB::table('document_canonical_specs')->whereRaw("catalogue_type_ids = '[]'::jsonb")->count())->toBe(0);
});

it('REQ-DOC-CANON-D2-002: the 17 missing types exist with spec names, security profile and a stable namespace', function () {
    $types = DB::table('document_types')->where('namespace', 'CANONICAL_SPEC')->get()->keyBy('canonical_spec_id');
    expect($types->keys()->sort()->values()->all())->toBe(D2_SPEC_IDS);
    $spec = (new CanonicalDocumentSpec())->documents();
    foreach ($types as $sid => $t) {
        $profile = CanonicalDocumentSpec::profile($spec[$sid]);
        expect($t->name_en)->toBe($spec[$sid]['name_en'])
            ->and($t->name_fr)->toBe($spec[$sid]['name_fr'])
            ->and($t->security_tier)->toBe($profile['tier_floor'])
            ->and($t->confidentiality_class)->toBe($profile['confidentiality_class'])
            ->and($t->status)->toBe('ACTIVE')
            ->and(json_decode($t->security_controls, true))->toHaveKey('qr');
    }
    // The register's own DOC-### ids are untouched; the near-synonym is recorded, not merged.
    expect(DB::table('document_types')->where('namespace', 'REGISTER')->count())->toBe(220)
        ->and($types['DOC-179']->same_as_type_id)->toBe('CLM-19')
        ->and($types['DOC-138']->security_level)->toBe('PUBLIC_VERIFIABLE');
});

it('REQ-DOC-CANON-D2-003: the new types join packs as CONDITIONAL only and the pack resolver does not generate them', function () {
    $items = DB::table('document_pack_items')->where('document_type_id', 'like', 'SPEC.%')->get();
    expect($items)->not->toBeEmpty()
        ->and($items->pluck('requirement')->unique()->all())->toBe(['CONDITIONAL']);
    $travel = DB::table('document_packs')->where('code', 'TRAVEL_NEW_BUSINESS_PACK')->value('id');
    expect(DB::table('document_pack_items')->where('document_pack_id', $travel)->where('document_type_id', 'SPEC.TRAVEL_INSURANCE_CERTIFICATE')->exists())->toBeTrue()
        ->and(DB::table('document_type_class_applicability')->where('class_code', 'TRAVEL')->where('document_type_id', 'SPEC.TRAVEL_INSURANCE_CERTIFICATE')->exists())->toBeTrue();

    $product = new App\Models\InsuranceProduct();
    $product->line_code = 'TRAVEL';
    $pack = app(DocumentPackResolver::class)->resolveForProduct($product, 'POLICY_ISSUED');
    $row = collect($pack['items'])->firstWhere('document_type_code', 'TRAVEL_INSURANCE_CERTIFICATE');
    expect($row)->not->toBeNull()->and($row['mode'])->not->toBe('GENERATE');
});

it('REQ-DOC-CANON-D2-004: seeding twice is idempotent and keeps the new types active', function () {
    $count = DB::table('document_types')->count();
    $items = DB::table('document_pack_items')->count();
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
    expect(DB::table('document_types')->count())->toBe($count)
        ->and(DB::table('document_pack_items')->count())->toBe($items)
        ->and(DB::table('document_types')->where('namespace', 'CANONICAL_SPEC')->where('status', 'ACTIVE')->count())->toBe(17);
});

it('REQ-DOC-CANON-D2-005: every previously unmapped detailed field rule is mapped to a platform source or names its missing source', function () {
    $counts = [];
    foreach (DB::table('document_canonical_specs')->whereNotNull('detailed_field_map')->pluck('detailed_field_map') as $json) {
        foreach (json_decode($json, true) as $f) {
            $counts[$f['status']] = ($counts[$f['status']] ?? 0) + 1;
            if (in_array($f['status'], ['MAPPED_PLATFORM_SOURCE', 'UNMAPPED_PENDING_VERIFICATION'], true)) {
                expect($f['source'] ?? '')->not->toBe(''); // a platform source, or the named gap
            }
        }
    }
    expect($counts['MAPPED_PLATFORM_SOURCE'] + $counts['UNMAPPED_PENDING_VERIFICATION'])->toBe(442)
        ->and($counts['MAPPED_PLATFORM_SOURCE'])->toBeGreaterThanOrEqual(400)
        ->and($counts['ENFORCED'])->toBe(180); // enforcement is unchanged: mapped rules render when present, never block
});

it('REQ-DOC-CANON-D2-006: mapped sources name real tables', function () {
    $tables = collect(DB::select("select table_name from information_schema.tables where table_schema = 'public'"))->pluck('table_name')->flip();
    foreach (DetailedFieldSourceMap::MAP as $bullet => [$key, $source]) {
        if ($key === null) {
            continue;
        }
        preg_match('/^([a-z_]+)/', $source, $m);
        expect($tables->has($m[1]) || str_starts_with($source, 'derived') || str_starts_with($source, 'sum('))->toBeTrue("$bullet -> $source");
    }
});
