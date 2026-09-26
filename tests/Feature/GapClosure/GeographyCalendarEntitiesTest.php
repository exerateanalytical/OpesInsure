<?php

declare(strict_types=1);

/*
 * Gap Closure Pack v1 file 02 — geography, public holidays / calendars, occupations, industries, legal entities.
 * REQ-MDM-003 (merge by code, aliases, no duplicates), REQ-CAL-001 (holiday rules -> yearly DRAFT dataset, maker-checker),
 * REQ-TMP-001 (one holiday source for BusinessCalendar), gated datasets registered in Data Readiness.
 */

use App\Application\DataReadiness\DataStatus;
use App\Application\MasterData\MasterDataSearch;
use App\Application\ReferenceDatasets\PublicHolidayGenerator;
use App\Application\Temporal\BusinessCalendar;
use App\Providers\GapClosureCalendarsServiceProvider;
use App\Providers\OwnerDecisionsServiceProvider;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(OwnerDecisionsServiceProvider::class);
    $this->app->register(GapClosureCalendarsServiceProvider::class);
    (new MasterDataSeeder)->run();
});

function gp2Hit(string $d, string $l, string $q): ?string
{
    return app(MasterDataSearch::class)->search($d, $l, $q, null, null, 5)[0]['code'] ?? null;
}

it('merges MINAT region codes, risk levels and legal entity types by code without duplicates [REQ-MDM-003]', function () {
    expect(DB::table('master_data_values')->where(['domain_code' => 'geography', 'list_code' => 'cameroon_region'])->count())->toBe(10)
        ->and(gp2Hit('geography', 'cameroon_region', 'SW'))->toBe('SUD_OUEST')
        ->and(json_decode(DB::table('master_data_values')->where(['list_code' => 'cameroon_region', 'code' => 'LITTORAL'])->value('attributes'), true)['official_code'])->toBe('LT')
        ->and(gp2Hit('occupations', 'risk_class', 'ELEVATED'))->toBe('HAZARDOUS')
        ->and(DB::table('master_data_values')->where(['domain_code' => 'occupations', 'list_code' => 'risk_class'])->pluck('code')->all())
        ->toContain('PROHIBITED_OR_REVIEW')->toHaveCount(5);

    $legal = DB::table('master_data_values')->where(['domain_code' => 'organizations', 'list_code' => 'legal_entity_type'])->pluck('code')->all();
    $owner = json_decode((string) file_get_contents(database_path('data/gap_closure_2026/02_geography_calendar_occupation_industry_entities.json')), true);
    foreach ($owner['legal_entity_types']['values'] as $v) {
        expect(in_array($v['code'], $legal, true) || gp2Hit('organizations', 'legal_entity_type', $v['code']) !== null)->toBeTrue("{$v['code']} not mapped");
    }
    expect(count($legal))->toBe(count(array_unique($legal)));

    // Idempotent.
    $n = DB::table('master_data_values')->count();
    (new MasterDataSeeder)->run();
    expect(DB::table('master_data_values')->count())->toBe($n);
})->group('REQ-MDM-003');

it('registers every gated dataset in Data Readiness without inventing values [REQ-MDM-003]', function () {
    $rows = DB::table('master_data_workflow_statuses')->where('source', 'GAP_CLOSURE_PACK_V1')->pluck('status', 'item_key');
    expect($rows['geography.full_administrative_dataset'])->toBe('PENDING_SOURCE')
        ->and($rows['geography.public_holidays'])->toBe('CONFIG_REQUIRED')
        ->and($rows['sla_calendars.business_calendars'])->toBe('CONFIG_REQUIRED')
        ->and($rows['occupations_industries.industry_catalogue'])->toBe('PENDING_SOURCE')
        ->and($rows['legal_entity_types.registration_document_matrix'])->toBe('UNVERIFIED')
        ->and(DataStatus::normalize('PENDING_OFFICIAL_IMPORT'))->toBe('PENDING_SOURCE')
        ->and(DataStatus::normalize('VERIFIED_PUBLIC_SOURCE'))->toBe('VERIFIED')
        ->and(DB::table('master_data_values')->where('list_code', 'cameroon_arrondissement')->count())->toBe(0)
        ->and(DB::table('reference_datasets')->count())->toBe(0);
})->group('REQ-MDM-003');

it('computes a yearly holiday preview from the Law 73/5 rules; Eid dates stay pending [REQ-CAL-001]', function () {
    expect(PublicHolidayGenerator::easterDays(2026))->toBe(15); // 5 April 2026
    $p = app(PublicHolidayGenerator::class)->preview(2023);
    $dates = array_column($p['entries'], 'date');
    expect($dates)->toContain('2023-01-01', '2023-01-02', '2023-02-11', '2023-04-07', '2023-05-18', '2023-05-20', '2023-08-15', '2023-12-25')
        ->and(array_column($p['pending'], 'code'))->toBe(['EID_AL_FITR', 'EID_AL_ADHA'])
        ->and($p['source_url'])->toContain('minfopra');
})->group('REQ-CAL-001');

it('drafts the year as an UNVERIFIED dataset that only counts after maker-checker activation [REQ-CAL-001] [REQ-TMP-001]', function () {
    $tenant = makeAuthTestTenant('gp2');
    $maker = makeAuthTestUser($tenant, ['reference_datasets.manage', 'reference_datasets.view']);
    $checker = makeAuthTestUser($tenant, ['reference_datasets.approve']);
    $h = ['X-Tenant-ID' => $tenant->id];
    Passport::actingAs($maker, [], 'api');
    $this->getJson('/api/v1/reference-datasets/public-holidays/preview?year=2026', $h)->assertOk()->assertJsonCount(2, 'data.pending');
    $res = $this->postJson('/api/v1/reference-datasets/public-holidays/generate', ['year' => 2026, 'extra' => [
        ['code' => 'EID_AL_FITR', 'date' => '2026-03-20', 'label' => 'Test Eid (fixture)', 'holiday_type' => 'DECREED', 'legal_reference' => 'Test fixture'],
    ]], $h)->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.verification_status', 'UNVERIFIED')
        ->assertJsonPath('data.code', 'CM_PUBLIC_HOLIDAYS_2026');
    expect(array_column($res->json('pending'), 'code'))->toBe(['EID_AL_ADHA']);
    $id = $res->json('data.id');

    expect((new BusinessCalendar)->isBusinessDay('2026-05-20'))->toBeTrue(); // DRAFT not consumed
    Passport::actingAs($checker, [], 'api');
    $this->postJson("/api/v1/reference-datasets/{$id}/activate", [], $h)->assertOk()->assertJsonPath('data.status', 'ACTIVE');
    expect((new BusinessCalendar)->isBusinessDay('2026-05-20'))->toBeFalse()
        ->and((new BusinessCalendar)->isBusinessDay('2026-04-03'))->toBeFalse()
        ->and((new BusinessCalendar)->isBusinessDay('2026-04-02'))->toBeTrue();

    Passport::actingAs(makeAuthTestUser($tenant, ['reference_datasets.view'], 'VIEWER'), [], 'api');
    $this->postJson('/api/v1/reference-datasets/public-holidays/generate', ['year' => 2027], $h)->assertForbidden();
})->group('REQ-CAL-001');
