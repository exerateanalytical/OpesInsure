<?php

declare(strict_types=1);

use App\Application\Approvals\ApprovalService;
use App\Application\Regulatory\CimaAuthorizationService;
use App\Application\Regulatory\CimaPublicationGuard;
use App\Application\Regulatory\CimaReadinessChecklist;
use App\Application\Regulatory\CimaSetupService;
use App\Application\Regulatory\OrganizationNameHistoryService;
use App\Models\ApprovalRequest;
use App\Models\Carrier;
use App\Models\InsurerAuthorization;
use App\Models\Partner;
use App\Models\Party;
use App\Models\Regulatory\InsurerRegulatoryAuthorization;
use App\Models\Regulatory\OrganizationNameHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(fn () => test()->artisan('opesinsure:seed-cima')->assertExitCode(0));

function b3aUser(string $name = 'User'): User
{
    $party = Party::create(['type' => 'PERSON', 'display_name' => $name, 'status' => 'ACTIVE']);

    return User::create(['full_name' => $name, 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'party_id' => $party->id, 'password' => 'x', 'locale' => 'fr', 'status' => 'ACTIVE']);
}

function b3aCarrier(array $attrs = []): Carrier
{
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'B3A Assurances '.Str::random(4), 'status' => 'ACTIVE']);

    return Carrier::create($attrs + ['party_id' => $party->id, 'cima_code' => 'B3A-'.Str::random(6), 'status' => 'ACTIVE', 'capabilities' => []]);
}

function b3aRegister(Carrier $c, string $branch = 'IARD', int $year = 2026, string $status = 'AUTHORIZED'): InsurerAuthorization
{
    return InsurerAuthorization::create(['carrier_id' => $c->id, 'reference_year' => $year, 'branch' => $branch, 'status' => $status,
        'effective_from' => "{$year}-01-01", 'source_authority' => 'DGTCFM', 'data_origin' => 'REGULATORY', 'is_official_register' => false]);
}

function b3aData(array $extra = []): array
{
    return $extra + ['authorization_reference' => 'ARRETE-B3A', 'source' => 'REGULATOR_DECREE', 'source_document' => 'https://example.test/arrete.pdf', 'effective_from' => '2026-01-01'];
}

it('REQ-DUP-017 REQ-CIMA-002 routes maker-checker through the approval engine and only then unlocks the branch', function () {
    $carrier = b3aCarrier();
    $svc = app(CimaAuthorizationService::class);
    $maker = b3aUser('Maker');
    $auth = $svc->record($carrier, b3aData(), ['CIMA_10_MOTOR_LIABILITY'], $maker);

    $req = ApprovalRequest::find($auth->approval_request_id);
    expect($auth->status)->toBe('PENDING_APPROVAL')
        ->and($req->action_code)->toBe(CimaAuthorizationService::APPROVAL_ACTION)
        ->and($req->source_table)->toBe('insurer_regulatory_authorizations')
        ->and($req->status)->toBe('PENDING')
        ->and($svc->isAuthorized($carrier->id, 'CIMA_10_MOTOR_LIABILITY'))->toBeFalse();

    expect(fn () => $svc->approve($auth, $maker))->toThrow(ValidationException::class);
    // The generic approvals inbox decides through the registered handler.
    app(ApprovalService::class)->approve($req, b3aUser('Checker'));
    expect($auth->refresh()->status)->toBe('ACTIVE')->and($req->refresh()->status)->toBe('APPROVED')
        ->and($svc->isAuthorized($carrier->id, 'CIMA_10_MOTOR_LIABILITY'))->toBeTrue();
});

it('REQ-DUP-017 inbox rejection rejects the authorization', function () {
    $carrier = b3aCarrier();
    $auth = app(CimaAuthorizationService::class)->record($carrier, b3aData(), ['CIMA_10_MOTOR_LIABILITY'], b3aUser());
    app(ApprovalService::class)->reject(ApprovalRequest::find($auth->approval_request_id), b3aUser('Checker'), 'Evidence illegible');
    expect($auth->refresh()->status)->toBe('REJECTED');
});

it('REQ-DUP-017 the official register feeds the canonical record and bounds its licence family', function () {
    $carrier = b3aCarrier();
    $reg = b3aRegister($carrier, 'IARD');
    $svc = app(CimaAuthorizationService::class);

    $auth = $svc->record($carrier, b3aData(), ['CIMA_10_MOTOR_LIABILITY'], b3aUser());
    expect($auth->register_authorization_id)->toBe($reg->id)->and($auth->licence_family)->toBe('IARD');

    // An IARD-only register insurer cannot claim a life branch.
    expect(fn () => $svc->record($carrier, b3aData(), ['CIMA_20_LIFE_DEATH'], b3aUser()))->toThrow(ValidationException::class, 'IARD only');
    // Mixed families must be recorded separately.
    expect(fn () => $svc->record($carrier, b3aData(), ['CIMA_10_MOTOR_LIABILITY', 'CIMA_20_LIFE_DEATH'], b3aUser()))->toThrow(ValidationException::class, 'separately');
    // A register row of another insurer cannot be linked.
    $other = b3aRegister(b3aCarrier(), 'IARD');
    expect(fn () => $svc->record($carrier, b3aData(['register_authorization_id' => $other->id]), ['CIMA_10_MOTOR_LIABILITY'], b3aUser()))->toThrow(ValidationException::class, 'does not belong');
});

it('REQ-CIMA-002 never invents authorizations: register rows alone keep the publication gate closed (Q2)', function () {
    $carrier = b3aCarrier(['is_official_register' => false]);
    b3aRegister($carrier, 'IARD');
    expect(InsurerRegulatoryAuthorization::where('carrier_id', $carrier->id)->exists())->toBeFalse()
        ->and(app(CimaAuthorizationService::class)->isAuthorized($carrier->id, 'CIMA_10_MOTOR_LIABILITY'))->toBeFalse();
});

it('REQ-CIMA-002 requires regulator evidence and a signed-in maker', function () {
    $carrier = b3aCarrier();
    $svc = app(CimaAuthorizationService::class);
    expect(fn () => $svc->record($carrier, b3aData(['source_document' => '']), ['CIMA_10_MOTOR_LIABILITY'], b3aUser()))->toThrow(ValidationException::class, 'evidence')
        ->and(fn () => $svc->record($carrier, b3aData(), ['CIMA_10_MOTOR_LIABILITY'], null))->toThrow(ValidationException::class, 'signed-in');
});

it('REQ-SEED-003 keeps an append-only name/brand history and never overwrites a year', function () {
    $carrier = b3aCarrier(['legal_name' => 'OLD NAME SA', 'trade_name' => 'Old', 'reference_year' => 2025]);
    $names = app(OrganizationNameHistoryService::class);
    expect($names->history($carrier))->toHaveCount(1);

    $carrier->update(['legal_name' => 'NEW NAME SA', 'trade_name' => 'New', 'reference_year' => 2026]);
    $h = $names->history($carrier);
    expect($h)->toHaveCount(2)
        ->and($h[0]->effective_until?->toDateString())->toBe('2026-01-01')
        ->and($h[1]->legal_name)->toBe('NEW NAME SA')
        ->and($names->nameOn($carrier, '2025-06-01'))->toBe('Old')
        ->and($names->nameOn($carrier, '2026-06-01'))->toBe('New');

    // Unchanged save adds nothing.
    $carrier->update(['status' => 'ACTIVE', 'legal_name' => 'NEW NAME SA']);
    expect($names->history($carrier))->toHaveCount(2);

    if (DB::getDriverName() === 'pgsql') {
        expect(fn () => OrganizationNameHistory::first()->update(['legal_name' => 'REWRITE']))->toThrow(Exception::class)
            ->and(fn () => OrganizationNameHistory::first()->delete())->toThrow(Exception::class);
    }
});

it('REQ-CIMA-005 builds INS-SET-CIMA-001..006 and scoped, effective-dated reporting mappings', function () {
    $carrier = b3aCarrier(['legal_name' => 'SETUP SA']);
    b3aRegister($carrier, 'IARD');
    $setup = app(CimaSetupService::class);
    $data = $setup->insurerSetup($carrier);
    expect(array_keys($data))->toContain('INS-SET-CIMA-001', 'INS-SET-CIMA-002', 'INS-SET-CIMA-003', 'INS-SET-CIMA-004', 'INS-SET-CIMA-005', 'INS-SET-CIMA-006')
        ->and($data['INS-SET-CIMA-001']['unverified'])->toBeTrue()
        ->and($data['INS-SET-CIMA-001']['register_source'])->toHaveCount(1)
        ->and(collect($data['INS-SET-CIMA-002'])->pluck('number'))->not->toContain(19);

    $cat = DB::table('regulatory_reporting_categories')->where('kind', 'ART_411_CATEGORY')->value('code');
    $cat2 = DB::table('regulatory_reporting_categories')->where('kind', 'ART_411_CATEGORY')->where('code', '<>', $cat)->value('code');
    $u = b3aUser();
    $setup->addReportingMapping('INS-SET-CIMA-004', $carrier->id, ['subject_type' => 'INSURANCE_LINE', 'subject_code' => 'MOTOR', 'target_code' => $cat, 'effective_from' => '2026-01-01'], $u);
    $setup->addReportingMapping('INS-SET-CIMA-004', $carrier->id, ['subject_type' => 'INSURANCE_LINE', 'subject_code' => 'MOTOR', 'target_code' => $cat2, 'effective_from' => '2026-06-01'], $u);
    $rows = collect($setup->mappingsFor('CARRIER', $carrier->id, 'INS-SET-CIMA-004'));
    expect($rows)->toHaveCount(2)->and($rows->where('status', 'ACTIVE')->first()['reporting_category_code'])->toBe($cat2)
        ->and($rows->where('status', 'ENDED')->first()['effective_until'])->toBe('2026-06-01');

    expect(fn () => $setup->addReportingMapping('INS-SET-CIMA-004', $carrier->id, ['subject_type' => 'INSURANCE_LINE', 'subject_code' => 'MOTOR', 'target_code' => 'NOPE'], $u))
        ->toThrow(ValidationException::class);
});

it('REQ-CIMA-005 REQ-CIMA-006 builds BRK-SET-CIMA-001..004 on Article 557 measures', function () {
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Courtier B3A', 'status' => 'ACTIVE']);
    $partner = Partner::create(['party_id' => $party->id, 'type' => 'BROKER', 'status' => 'ACTIVE', 'legal_name' => 'COURTIER B3A', 'compliance' => []]);
    $setup = app(CimaSetupService::class);
    $u = b3aUser();

    $setup->addReportingMapping('BRK-SET-CIMA-001', $partner->id, ['subject_type' => 'REPORTING_MEASURE', 'subject_code' => 'ENABLED', 'target_code' => 'PREMIUM_WRITTEN'], $u);
    $setup->addReportingMapping('BRK-SET-CIMA-004', $partner->id, ['subject_type' => 'COMMISSION_TYPE', 'subject_code' => 'BROKER_COMMISSION', 'target_code' => 'COMMISSION_RECORDED'], $u);
    expect(fn () => $setup->addReportingMapping('BRK-SET-CIMA-004', $partner->id, ['subject_type' => 'COMMISSION_TYPE', 'subject_code' => 'BROKER_COMMISSION', 'target_code' => 'PREMIUM_WRITTEN'], $u))
        ->toThrow(ValidationException::class, 'maps to');

    $data = $setup->brokerSetup($partner);
    expect(collect($data['BRK-SET-CIMA-001']))->toHaveCount(7)
        ->and(collect($data['BRK-SET-CIMA-001'])->firstWhere('code', 'PREMIUM_WRITTEN')['enabled'])->toBeTrue()
        ->and($data['BRK-SET-CIMA-004'][0]['measure_code'])->toBe('COMMISSION_RECORDED')
        ->and($data['partner']['name_history'])->toHaveCount(1);
});

it('REQ-CIMA-006 evaluates the CIMA-ready checklist without inventing authorizations', function () {
    $carrier = b3aCarrier();
    $result = app(CimaReadinessChecklist::class)->evaluate();
    $items = collect($result['items'])->keyBy('label');
    expect($result['reconstructed'])->toBeTrue()
        ->and($items['Article 328 branches 1-23 present']['status'])->toBe('PASS')
        ->and($items['Branches 14 and 15 never accessory']['status'])->toBe('PASS')
        ->and($items['Article 557 intermediary measures (written/collected premium, recorded/collected commission, rate, current/prior period)']['status'])->toBe('PASS')
        ->and($items['No DEMO authorization on a real insurer']['status'])->toBe('PASS')
        ->and(array_sum($result['totals']))->toBe(count($result['items']));

    // Activating through the engine keeps the maker-checker item green.
    $svc = app(CimaAuthorizationService::class);
    $svc->approve($svc->record($carrier, b3aData(), ['CIMA_10_MOTOR_LIABILITY'], b3aUser()), b3aUser('Checker'));
    $items = collect(app(CimaReadinessChecklist::class)->evaluate()['items'])->keyBy('label');
    expect($items['Active authorizations approved through the approval engine by a different user']['status'])->toBe('PASS')
        ->and($items['Every active authorization cites regulator evidence']['status'])->toBe('PASS');
});

it('REQ-CIMA-002 publication guard still blocks an unauthorized insurer', function () {
    $carrier = b3aCarrier();
    $line = \App\Models\InsuranceLine::firstOrCreate(['code' => 'MOTOR'], ['name' => ['en' => 'Motor'], 'description' => ['en' => 'Motor'], 'status' => 'ACTIVE', 'risk_schema' => []]);
    $product = \App\Models\InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => 'MOTOR', 'code' => 'M-'.Str::random(5), 'name' => 'Motor', 'version' => 1,
        'effective_from' => '2026-01-01', 'status' => 'DRAFT', 'coverages' => [], 'eligibility_rules' => ['conditions' => []], 'created_by' => b3aUser()->id, 'regulatory_reference' => 'R']);
    $cov = \App\Models\CoverageDefinition::firstOrCreate(['insurance_line_id' => $line->id, 'code' => 'THIRD_PARTY'], ['name' => ['en' => 'TP'], 'description' => ['en' => 'TP'], 'limit_type' => 'AMOUNT', 'mandatory' => false, 'status' => 'ACTIVE']);
    $product->coverageDefinitions()->attach($cov->id, ['display_order' => 0, 'configuration' => '{}']);
    b3aRegister($carrier, 'IARD');
    expect(implode(' ', app(CimaPublicationGuard::class)->violations($product)))->toContain('insurer not authorized');
});

it('REQ-CIMA-005 REQ-CIMA-006 renders the insurer/broker setup screens and the checklist for platform admins only', function () {
    require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
    $carrier = b3aCarrier(['legal_name' => 'RENDER SA']);
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Courtier Render', 'status' => 'ACTIVE']);
    $partner = Partner::create(['party_id' => $party->id, 'type' => 'BROKER', 'status' => 'ACTIVE', 'legal_name' => 'COURTIER RENDER', 'compliance' => []]);
    $tenant = App\Models\Tenant::create(['type' => 'CARRIER', 'legal_name' => 'B3A Tenant', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'fr']);
    $this->actingAs(makeMobileTenantStaffUser($tenant, '+237670003301', 'PLATFORM_ADMIN'), 'web');
    $this->get("/admin/cima-insurer-setup?carrier={$carrier->id}")->assertOk()->assertSee('INS-SET-CIMA-006')->assertSee('owner question Q2');
    $this->get("/admin/cima-broker-setup?partner={$partner->id}")->assertOk()->assertSee('BRK-SET-CIMA-004')->assertSee('Prime émise');
    $this->get('/admin/cima-regulatory-dictionary')->assertOk()->assertSee('CIMA-ready checklist')->assertSee('CIMA-READY-01');

    $this->flushSession();
    app('auth')->forgetGuards();
    $this->actingAs(makeMobileTenantStaffUser($tenant, '+237670003302', 'CLAIMS_OFFICER'), 'web');
    $this->get('/admin/cima-insurer-setup')->assertForbidden();
    $this->get('/admin/cima-broker-setup')->assertForbidden();
});
