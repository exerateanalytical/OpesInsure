<?php

declare(strict_types=1);

/**
 * Gap Closure Pack 01 — Insurance Market, Product, Agreement & Commission Master.
 * REQ-GC-001 REQ-CIMA-002 REQ-DUP-017 REQ-SEED-004 REQ-COM-002 REQ-IMP-001 REQ-SEED-003
 */

use App\Application\CarrierOperations\Agreements\CarrierBrokerAgreementService;
use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\DataReadiness\NonProductionDataException;
use App\Application\FinancialDistribution\CommissionService;
use App\Application\Import\ImportPipeline;
use App\Application\Import\ImportTargetRegistry;
use App\Application\MarketData\MarketDataGates;
use App\Application\Regulatory\CimaAuthorizationService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Carrier;
use App\Models\CommissionRuleVersion;
use App\Models\Partner;
use App\Models\Regulatory\InsurerRegulatoryAuthorization;
use Database\Seeders\CameroonInsuranceRegisterSeeder;
use Database\Seeders\CameroonInsurerDirectorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

function gp1File(string $csv): string
{
    $p = tempnam(sys_get_temp_dir(), 'gp1').'.csv';
    file_put_contents($p, $csv);

    return $p;
}

beforeEach(function () {
    $this->tenant = makeAuthTestTenant('GP1');
    app(TenantContext::class)->set($this->tenant->id);
    test()->artisan('opesinsure:seed-cima')->assertExitCode(0);
    test()->seed(CameroonInsuranceRegisterSeeder::class);
});

it('REQ-GC-001 reconciles the pack insurer directory with the seeded one: identical, no duplicate carriers or profiles', function () {
    $pack = database_path('data/gap_closure_2026/OpesInsure_Cameroon_29_Insurers_Institutional_Directory_v1.json');
    expect(hash_file('sha256', $pack))->toBe(hash_file('sha256', database_path(CameroonInsurerDirectorySeeder::FILE)));

    $carriers = Carrier::count();
    test()->seed(CameroonInsurerDirectorySeeder::class);
    test()->seed(CameroonInsurerDirectorySeeder::class);
    expect(Carrier::count())->toBe($carriers)->and($carriers)->toBe(29)
        ->and(DB::table('institution_profiles')->whereNotNull('carrier_id')->count())->toBe(29)
        ->and(DB::table('institution_profiles')->whereNotNull('carrier_id')->distinct()->count('carrier_id'))->toBe(29);
});

it('REQ-CIMA-002 imports CIMA branch authorizations with evidence through the pipeline and maker-checker; nothing is authorized before approval', function () {
    $carrier = Carrier::where('licence_branch', 'IARD')->orderBy('regulator_sequence')->first();
    $branch = DB::table('insurance_branches')->where('regime', 'CIMA')->where('reserved', false)->where('business_family', 'IARD')->orderBy('number')->first();
    $maker = makeAuthTestUser($this->tenant, ['imports.create']);
    $checker = makeAuthTestUser($this->tenant, ['imports.approve']);
    $third = makeAuthTestUser($this->tenant, ['regulatory.authorizations.approve']);

    $csv = "insurer,cima_branch_codes,decision_reference,authority,source_document,effective_from,status\n"
        ."{$carrier->canonical_id},{$branch->number},CRCA-2026-001,CIMA_CRCA,https://evidence.test/crca-001.pdf,2026-01-01,AUTHORIZED\n"
        ."{$carrier->canonical_id},{$branch->number},CRCA-2026-002,CIMA_CRCA,,2026-01-01,AUTHORIZED\n"
        ."{$carrier->canonical_id},{$branch->number},CRCA-2026-003,CIMA_CRCA,https://e.test/x.pdf,2026-01-01,REVOKED\n"
        ."UNKNOWN INSURER,{$branch->number},CRCA-2026-004,CIMA_CRCA,https://e.test/y.pdf,2026-01-01,\n";
    $pipe = app(ImportPipeline::class);
    $batch = $pipe->upload('cima_insurer_authorizations', [], gp1File($csv), 'auth.csv', $maker);
    expect($batch->report['valid'])->toBe(1)->and(count($batch->report['errors']))->toBe(3);

    $good = implode("
", array_slice(explode("
", $csv), 0, 2))."
";
    $batch = $pipe->approve($pipe->submit($pipe->upload('cima_insurer_authorizations', [], gp1File($good), 'auth.csv', $maker), $maker), $checker);
    $auth = InsurerRegulatoryAuthorization::where('authorization_reference', 'CRCA-2026-001')->firstOrFail();
    expect($batch->status)->toBe('IMPORTED')->and($auth->status)->toBe('PENDING_APPROVAL')->and($auth->import_batch_id)->toBe($batch->id)
        ->and(app(CimaAuthorizationService::class)->isAuthorized($carrier->id, $branch->code))->toBeFalse();

    // Re-import is a DUPLICATE, never a second agrément.
    $again = $pipe->upload('cima_insurer_authorizations', [], gp1File($good), 'auth.csv', $maker);
    expect(count($again->report['duplicates']))->toBe(1)->and($again->report['valid'])->toBe(0);

    $svc = app(CimaAuthorizationService::class);
    $svc->approve($auth, $third);
    expect($auth->refresh()->status)->toBe('ACTIVE');
    $svc->changeStatus($auth, 'SUSPENDED', $third, 'Solvency margin breach');
    expect($auth->refresh()->suspension_reason)->toBe('Solvency margin breach');
    $svc->changeStatus($auth, 'REVOKED', $third, 'CRCA withdrawal');
    expect($auth->refresh()->revocation_reason)->toBe('CRCA withdrawal');
});

it('REQ-GC-001 enriches DGTCFM brokers in the canonical directory, never creating or overwriting a broker', function () {
    expect(Partner::where('type', 'BROKER')->where('is_official_register', true)->count())->toBe(123);
    $broker = Partner::where('type', 'BROKER')->where('is_official_register', true)->orderBy('regulator_sequence')->first();
    $maker = makeAuthTestUser($this->tenant, ['imports.create']);
    $checker = makeAuthTestUser($this->tenant, ['imports.approve']);
    $partners = Partner::count();

    $csv = "official_sequence,legal_name,locality,postal_box,phones,emails,responsible_person,authorized_year,branches,source_url,verification_status\n"
        ."{$broker->regulator_sequence},\"{$broker->legal_name}\",Douala,BP 1,+237 600 00 00 01;+237 600 00 00 02,info@broker.test,,2001,Siege@Douala@Akwa@+237 600 00 00 01;Agence@Yaounde@@,https://broker.test,PARTIALLY_VERIFIED\n"
        .",NOT A REGISTERED BROKER SARL,Douala,,,,,,,https://x.test,\n";
    $pipe = app(ImportPipeline::class);
    $batch = $pipe->upload('broker_directory_enrichment', [], gp1File($csv), 'brokers.csv', $maker);
    expect($batch->report['valid'])->toBe(1)->and(count($batch->report['errors']))->toBe(1);
    $good = implode("
", array_slice(explode("
", $csv), 0, 2))."
";
    $pipe->approve($pipe->submit($pipe->upload('broker_directory_enrichment', [], gp1File($good), 'b.csv', $maker), $maker), $checker);

    $profile = DB::table('institution_profiles')->where('partner_id', $broker->id)->first();
    expect(Partner::count())->toBe($partners)
        ->and($profile->carrier_id)->toBeNull()->and($profile->locality)->toBe('Douala')->and($profile->responsible_person)->toBeNull()
        ->and(json_decode($profile->phones, true))->toHaveCount(2)->and($profile->verification_status)->toBe('PARTIALLY_VERIFIED')
        ->and(DB::table('institution_offices')->where('partner_id', $broker->id)->count())->toBe(2);

    $again = $pipe->upload('broker_directory_enrichment', [], gp1File($csv), 'brokers.csv', $maker);
    expect(count($again->report['duplicates']))->toBe(1);
});

it('REQ-SEED-004 agreements keep contract terms, default to PENDING_PRIVATE_SOURCE and need a verified signed source to activate in production', function () {
    $carrier = Carrier::first();
    $broker = Partner::where('type', 'BROKER')->first();
    $maker = makeAuthTestUser($this->tenant, []);
    $checker = makeAuthTestUser($this->tenant, []);
    $svc = app(CarrierBrokerAgreementService::class);
    $a = $svc->create($maker, ['carrier_id' => $carrier->id, 'partner_id' => $broker->id, 'agreement_number' => 'GP1-AGR-1', 'effective_from' => '2026-01-01',
        'agreement_type' => 'BROKERAGE', 'authorized_cima_branches' => ['CIMA_03'], 'data_exchange_mode' => 'API', 'premium_remittance_terms' => ['days' => 30]]);
    $svc->setProduct($maker, $a->id, ['line_code' => 'MOTOR', 'can_quote' => true, 'can_endorse' => true]);
    $row = DB::table('carrier_broker_agreements')->find($a->id);
    expect($row->data_status)->toBe('PENDING_PRIVATE_SOURCE')->and($row->agreement_type)->toBe('BROKERAGE')
        ->and(json_decode($row->authorized_cima_branches, true))->toBe(['CIMA_03']);
    expect($svc->permits($broker->id, $carrier->id, 'MOTOR', null, 'endorse')['reason'])->toBe('AGREEMENT_INACTIVE');

    config(['data_readiness.enforce' => true]);
    expect(fn () => $svc->transition($checker, $a->id, 'ACTIVE', 'go'))->toThrow(ValidationException::class);
    $gates = app(MarketDataGates::class);
    expect(fn () => $gates->verifyAgreementSource($a->id, $maker, 'https://docs.test/signed.pdf'))->toThrow(ValidationException::class);
    $gates->verifyAgreementSource($a->id, $checker, 'https://docs.test/signed.pdf');
    expect($svc->transition($checker, $a->id, 'ACTIVE', 'go')->status)->toBe('ACTIVE')
        ->and($svc->permits($broker->id, $carrier->id, 'MOTOR', null, 'endorse')['allowed'])->toBeTrue()
        ->and($svc->permits($broker->id, $carrier->id, 'MOTOR', null, 'renew')['allowed'])->toBeFalse();
});

it('REQ-COM-002 imports commission tables as DRAFT rules that cite their source; rates are never defaulted; approval gated in production', function () {
    $carrier = Carrier::first();
    $broker = Partner::where('type', 'BROKER')->first();
    $maker = makeAuthTestUser($this->tenant, ['imports.create']);
    $checker = makeAuthTestUser($this->tenant, ['imports.approve']);
    $csv = "insurer,broker,line_code,beneficiary_type,basis_type,rate_percent,fixed_amount,effective_from,source_document,earning_event\n"
        ."{$carrier->canonical_id},\"{$broker->legal_name}\",MOTOR,BROKER,COLLECTED_PREMIUM,12.5,,2026-01-01,https://docs.test/schedule.pdf,PREMIUM_COLLECTED\n"
        ."{$carrier->canonical_id},,MOTOR,BROKER,COLLECTED_PREMIUM,,,2026-01-01,https://docs.test/schedule.pdf,\n"
        ."{$carrier->canonical_id},,HEALTH,AGENT,WRITTEN_PREMIUM,10,,2026-01-01,,\n"
        ."{$carrier->canonical_id},,MOTOR,ALIEN,WRITTEN_PREMIUM,10,,2026-01-01,https://d.test,\n";
    $pipe = app(ImportPipeline::class);
    expect(fn () => $pipe->upload('commission_tables', [], gp1File($csv), 'c.csv', $maker))->toThrow(ValidationException::class);
    $batch = $pipe->upload('commission_tables', ['tenant_id' => $this->tenant->id], gp1File($csv), 'c.csv', $maker);
    expect($batch->report['valid'])->toBe(1)->and(count($batch->report['errors']))->toBe(3);
    $good = implode("
", array_slice(explode("
", $csv), 0, 2))."
";
    $pipe->approve($pipe->submit($pipe->upload('commission_tables', ['tenant_id' => $this->tenant->id], gp1File($good), 'c.csv', $maker), $maker), $checker);

    $rule = CommissionRuleVersion::where('carrier_id', $carrier->id)->where('beneficiary_type', 'BROKER')->firstOrFail();
    expect($rule->status)->toBe('DRAFT')->and($rule->basis_points)->toBe(1250)->and($rule->basis_type)->toBe('COLLECTED_PREMIUM')
        ->and($rule->data_status)->toBe('PENDING_VERIFICATION')->and($rule->source_document)->toBe('https://docs.test/schedule.pdf');

    // Production gate: a rule without any source cannot be approved.
    config(['data_readiness.enforce' => true]);
    $bare = app(CommissionService::class)->createRule(['tenant_id' => $this->tenant->id, 'carrier_id' => $carrier->id, 'effective_from' => '2026-02-01', 'basis_points' => 500, 'holdback_basis_points' => 0], $maker);
    expect(fn () => app(CommissionService::class)->approveRule($bare, $checker))->toThrow(NonProductionDataException::class);
    expect(app(CommissionService::class)->approveRule($rule, $maker)->status)->toBe('APPROVED');
});

it('REQ-GC-001 registers every gate in Data Readiness and serves the readiness endpoint', function () {
    expect(app(ImportTargetRegistry::class)->options())->toHaveKeys(['cima_insurer_authorizations', 'broker_directory_enrichment', 'commission_tables']);
    $items = collect(app(DataReadinessRegistry::class)->items())->keyBy('item');
    expect($items['gap01_insurer_authorizations']['status'])->toBe('PENDING_SOURCE')
        ->and($items['gap01_insurer_authorizations']['production_usable'])->toBeFalse()
        ->and($items['gap01_agreements']['status'])->toBe('PENDING_SOURCE')
        ->and($items['gap01_commission_tables']['status'])->toBe('PENDING_SOURCE')
        ->and($items['gap01_product_catalogue']['status'])->toBe('PENDING_SOURCE')
        ->and($items['gap01_taxes_levies']['production_usable'])->toBeFalse()
        ->and($items['gap01_broker_directory']['status'])->toBe('VERIFIED')
        ->and($items['gap01_motor_usage_insurer_pricing']['status'])->toBe('PENDING_SOURCE')
        ->and($items['gap01_motor_usage_codes']['domain'])->toBe('vehicles');

    // Direct B2C stays off for every product version until an insurer source is attached.
    expect(DB::table('insurance_products')->where('direct_b2c_enabled', true)->count())->toBe(0);

    Passport::actingAs(makeAuthTestUser($this->tenant, ['data_readiness.view']));
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/market-data/readiness')->assertOk()
        ->assertJsonPath('data.meta.regulatory_regime', 'CIMA')->assertJsonCount(3, 'data.import_targets');
    Passport::actingAs(makeAuthTestUser($this->tenant, []));
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/market-data/readiness')->assertForbidden();
});
