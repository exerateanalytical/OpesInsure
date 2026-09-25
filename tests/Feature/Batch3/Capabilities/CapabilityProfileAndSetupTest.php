<?php

declare(strict_types=1);

/**
 * @covers REQ-AOM-001 REQ-SET-002 REQ-SET-003
 */

use App\Application\Capabilities\CapabilityPinner;
use App\Application\Capabilities\CapabilityProfileService;
use App\Application\Capabilities\CapabilityResolver;
use App\Application\CarrierOperations\Setup\CarrierSetupService;
use App\Application\Partners\Setup\PartnerSetupService;
use App\Models\Carrier;
use App\Models\DocumentIssuanceProfile;
use App\Models\InsuranceLine;
use App\Models\Partner;
use App\Models\Party;
use App\Models\User;
use App\Providers\CapabilitySetupServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

beforeEach(function () {
    if (! app()->providerIsLoaded(CapabilitySetupServiceProvider::class)) {
        app()->register(CapabilitySetupServiceProvider::class);
    }
});

function b3dUser(): User
{
    return User::create(['full_name' => 'B3D '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

function b3dCarrier(): Carrier
{
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'B3D Assurances '.Str::random(4), 'status' => 'ACTIVE']);

    return Carrier::create(['party_id' => $party->id, 'cima_code' => 'B3D-'.Str::random(6), 'status' => 'ACTIVE', 'capabilities' => []]);
}

function b3dProduct(Carrier $c, string $status = 'ACTIVE', string $line = 'MOTOR'): string
{
    InsuranceLine::firstOrCreate(['code' => $line], ['name' => ['en' => $line], 'description' => ['en' => $line], 'status' => 'ACTIVE', 'risk_schema' => []]);
    $id = (string) Str::uuid();
    DB::table('insurance_products')->insert(['id' => $id, 'carrier_id' => $c->id, 'line_code' => $line, 'code' => 'P-'.Str::random(5), 'name' => json_encode(['en' => 'Motor']),
        'version' => 1, 'effective_from' => '2026-01-01', 'status' => $status, 'coverages' => '[]', 'eligibility_rules' => '{}', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function b3dTariff(string $productId): void
{
    DB::table('tariff_versions')->insert(['id' => (string) Str::uuid(), 'insurance_product_id' => $productId, 'version' => 1, 'effective_from' => '2026-01-01',
        'status' => 'APPROVED', 'input_schema' => '{}', 'rules' => '{}', 'rules_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
}

function b3dActiveProfile(Carrier $c, array $modes): \App\Application\Capabilities\Models\CapabilityProfile
{
    $svc = app(CapabilityProfileService::class);
    $maker = b3dUser();
    $p = $svc->draft($c, $modes, $maker);
    $svc->submit($p, $maker);

    return $svc->approve($p->refresh(), b3dUser());
}

// ---------------------------------------------------------------- REQ-AOM-001

it('REQ-AOM-001 exposes all MPS §3 capabilities with MANUAL/CONFIGURED/HYBRID/REMOTE_API and maps specific modes', function () {
    foreach (['PRODUCT_CATALOGUE', 'QUOTATION', 'RATING', 'UNDERWRITING', 'PAYMENT', 'POLICY_ISSUANCE', 'DOCUMENT_GENERATION', 'CLAIMS_INTAKE', 'RENEWAL', 'COMMISSION',
        'ACCOUNTING', 'PROVIDER_MANAGEMENT', 'REINSURANCE', 'REGULATORY_REPORTING'] as $cap) {
        $modes = \App\Application\Capabilities\CapabilityCatalogue::modes($cap);
        expect($modes)->toContain('MANUAL', 'CONFIGURED', 'HYBRID', 'REMOTE_API');
    }
    expect(\App\Application\Capabilities\CapabilityCatalogue::executionMode('RATING', 'CARRIER_API'))->toBe('REMOTE_API');
    expect(\App\Application\Capabilities\CapabilityCatalogue::executionMode('RATING', 'NONSENSE'))->toBeNull();
});

it('REQ-AOM-001 defaults an unconfigured insurer to MANUAL at LEVEL_1 (manual mode is always supported)', function () {
    $c = b3dCarrier();
    $r = app(CapabilityResolver::class);
    expect($r->mode($c->id, 'QUOTATION'))->toMatchArray(['mode' => 'MANUAL', 'execution_mode' => 'MANUAL', 'source' => 'DEFAULT']);
    expect($r->mode($c->id, 'POLICY_ISSUANCE')['mode'])->toBe('MANUAL_UPLOAD_CARRIER');
    expect(CapabilityResolver::maturity($r->all($c->id), false)['code'])->toBe('LEVEL_1_REGISTRY');
});

it('REQ-AOM-001 AT1 product override beats class override beats carrier default', function () {
    $c = b3dCarrier();
    $motor = b3dProduct($c);
    $other = b3dProduct($c);
    $health = b3dProduct($c, 'ACTIVE', 'HEALTH');
    b3dActiveProfile($c, [
        ['capability' => 'QUOTATION', 'mode' => 'MANUAL'],
        ['capability' => 'QUOTATION', 'mode' => 'HYBRID', 'scope_class_code' => 'MOTOR'],
        ['capability' => 'QUOTATION', 'mode' => 'CONFIGURED', 'scope_product_id' => $motor],
    ]);
    $r = app(CapabilityResolver::class);
    expect($r->mode($c->id, 'QUOTATION', $motor))->toMatchArray(['mode' => 'CONFIGURED', 'scope' => 'PRODUCT']);
    expect($r->mode($c->id, 'QUOTATION', $other))->toMatchArray(['mode' => 'HYBRID', 'scope' => 'CLASS']);
    expect($r->mode($c->id, 'QUOTATION', $health))->toMatchArray(['mode' => 'MANUAL', 'scope' => 'CARRIER']);
});

it('REQ-AOM-001 AT2 a pinned mode survives a profile change; pins are immutable in the database', function () {
    $c = b3dCarrier();
    $v1 = b3dActiveProfile($c, [['capability' => 'QUOTATION', 'mode' => 'MANUAL']]);
    $quoteId = (string) Str::uuid();
    $pin = app(CapabilityPinner::class)->pin('quote', $quoteId, $c->id, 'QUOTATION');
    expect($pin->mode)->toBe('MANUAL')->and($pin->profile_version)->toBe(1);

    $this->travel(5)->seconds();
    $v2 = b3dActiveProfile($c, [['capability' => 'QUOTATION', 'mode' => 'HYBRID']]);
    expect($v1->refresh()->status)->toBe('SUPERSEDED')->and($v2->status)->toBe('ACTIVE');
    expect(app(CapabilityResolver::class)->mode($c->id, 'QUOTATION')['mode'])->toBe('HYBRID');
    $again = app(CapabilityPinner::class)->pin('quote', $quoteId, $c->id, 'QUOTATION');
    expect($again->id)->toBe($pin->id)->and($again->mode)->toBe('MANUAL');
    // historical resolution still sees v1
    expect(app(CapabilityResolver::class)->mode($c->id, 'QUOTATION', null, $pin->pinned_at)['profile_version'])->toBe(1);

    $thrown = null;
    try {
        DB::transaction(fn () => DB::table('capability_pins')->where('id', $pin->id)->update(['mode' => 'HYBRID']));
    } catch (QueryException $e) {
        $thrown = $e;
    }
    expect($thrown)->not->toBeNull();
});

it('REQ-AOM-001 AT3 maker-checker: the maker cannot approve the profile; only one draft at a time', function () {
    $c = b3dCarrier();
    $svc = app(CapabilityProfileService::class);
    $maker = b3dUser();
    $p = $svc->draft($c, [['capability' => 'QUOTATION', 'mode' => 'MANUAL']], $maker);
    expect(fn () => $svc->draft($c, [], $maker))->toThrow(ValidationException::class);
    $svc->submit($p, $maker);
    expect(fn () => $svc->approve($p->refresh(), $maker))->toThrow(ValidationException::class);
    expect($svc->approve($p->refresh(), b3dUser())->status)->toBe('ACTIVE');
});

it('REQ-AOM-001 AT4 incoherent modes are rejected: configured pricing without tariff, API without integration client, invalid mode', function () {
    $c = b3dCarrier();
    $svc = app(CapabilityProfileService::class);
    $maker = b3dUser();
    expect(fn () => $svc->draft($c, [['capability' => 'RATING', 'mode' => 'TELEPATHY']], $maker))->toThrow(ValidationException::class);

    $p = $svc->draft($c, [['capability' => 'RATING', 'mode' => 'CONFIGURED_RULES'], ['capability' => 'QUOTATION', 'mode' => 'REMOTE_API']], $maker);
    expect($svc->incoherences($p))->toHaveCount(2);
    expect(fn () => $svc->submit($p, $maker))->toThrow(ValidationException::class);

    b3dTariff(b3dProduct($c));
    $client = (string) Str::uuid();
    DB::table('integration_clients')->insert(['id' => $client, 'name' => 'Carrier API', 'client_id' => 'b3d-'.Str::random(8), 'client_secret_hash' => 'x', 'scopes' => '[]', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    $svc->replaceModes($p, [['capability' => 'RATING', 'mode' => 'CONFIGURED_RULES'], ['capability' => 'QUOTATION', 'mode' => 'REMOTE_API', 'config' => ['integration_client_id' => $client]]], $maker);
    expect($svc->incoherences($p->refresh()))->toBe([]);
    expect($svc->submit($p, $maker)->status)->toBe('SUBMITTED');
});

it('REQ-AOM-001 reuses the document engine issuance profile for POLICY_ISSUANCE / DOCUMENT_GENERATION (no duplicate source)', function () {
    $c = b3dCarrier();
    $r = app(CapabilityResolver::class);
    DocumentIssuanceProfile::create(['carrier_id' => $c->id, 'issuance_mode' => 'OPES_GENERATED', 'opes_rendering_authorized' => true, 'authorization_reference' => 'AUTH-B3D', 'default_language' => 'BILINGUAL']);
    expect($r->mode($c->id, 'POLICY_ISSUANCE'))->toMatchArray(['mode' => 'OPES_GENERATED', 'execution_mode' => 'CONFIGURED', 'source' => 'DOCUMENT_ISSUANCE_PROFILE']);
    expect($r->mode($c->id, 'DOCUMENT_GENERATION')['mode'])->toBe('OPES_TEMPLATE');

    // a contradicting explicit row is refused at submission
    $svc = app(CapabilityProfileService::class);
    $maker = b3dUser();
    $p = $svc->draft($c, [['capability' => 'POLICY_ISSUANCE', 'mode' => 'MANUAL_UPLOAD_BROKER']], $maker);
    expect($svc->incoherences($p))->toHaveCount(1);
    expect(fn () => $svc->submit($p, $maker))->toThrow(ValidationException::class);
});

it('REQ-AOM-001 derives maturity LEVEL_2..LEVEL_5 from the modes (descriptor, not a gate)', function () {
    $c = b3dCarrier();
    $client = (string) Str::uuid();
    DB::table('integration_clients')->insert(['id' => $client, 'name' => 'API', 'client_id' => 'b3d-'.Str::random(8), 'client_secret_hash' => 'x', 'scopes' => '[]', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    $manual = [['capability' => 'QUOTATION', 'mode' => 'MANUAL'], ['capability' => 'POLICY_ISSUANCE', 'mode' => 'MANUAL_UPLOAD_CARRIER'], ['capability' => 'RENEWAL', 'mode' => 'MANUAL'],
        ['capability' => 'CLAIMS_INTAKE', 'mode' => 'BROKER_ASSISTED'], ['capability' => 'COMMISSION', 'mode' => 'MANUAL_STATEMENT']];
    expect(b3dActiveProfile($c, $manual)->maturity_code)->toBe('LEVEL_2_OPERATIONAL');

    $api = fn ($cap, $mode) => ['capability' => $cap, 'mode' => $mode, 'config' => ['integration_client_id' => $client]];
    expect(b3dActiveProfile($c, [...$manual, $api('PAYMENT', 'EXTERNAL_PROVIDER')])->maturity_code)->toBe('LEVEL_4_CONNECTED');
    expect(b3dActiveProfile($c, [$api('QUOTATION', 'REMOTE_API'), $api('POLICY_ISSUANCE', 'INSURER_API'), $api('CLAIMS_INTAKE', 'API_SYNCHRONIZED'), $api('PAYMENT', 'EXTERNAL_PROVIDER'),
        ['capability' => 'RENEWAL', 'mode' => 'MANUAL'], ['capability' => 'COMMISSION', 'mode' => 'MANUAL_STATEMENT']])->maturity_code)->toBe('LEVEL_5_INTEGRATED');
});

it('REQ-AOM-001 HTTP: draft, submit, approve by another user, resolve and maturity', function () {
    $tenant = makeAuthTestTenant('b3d');
    $maker = makeAuthTestUser($tenant, ['capability_profiles.view', 'capability_profiles.manage']);
    $checker = makeAuthTestUser($tenant, ['capability_profiles.view', 'capability_profiles.approve']);
    $c = b3dCarrier();
    Passport::actingAs($maker);
    $id = $this->postJson("/api/v1/carriers/{$c->id}/capability-profiles", ['modes' => [['capability' => 'QUOTATION', 'mode' => 'MANUAL']]], tenantHeader($tenant))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/capability-profiles/{$id}/submit", [], tenantHeader($tenant))->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
    $this->postJson("/api/v1/capability-profiles/{$id}/approve", [], tenantHeader($tenant))->assertForbidden();
    Passport::actingAs($checker);
    $this->postJson("/api/v1/capability-profiles/{$id}/approve", [], tenantHeader($tenant))->assertOk()->assertJsonPath('data.status', 'ACTIVE');
    $this->getJson("/api/v1/carriers/{$c->id}/capabilities?capability=QUOTATION", tenantHeader($tenant))->assertOk()->assertJsonPath('data.source', 'CAPABILITY_PROFILE');
    $this->getJson("/api/v1/carriers/{$c->id}/capabilities/maturity", tenantHeader($tenant))->assertOk()->assertJsonPath('data.code', 'LEVEL_1_REGISTRY');
    $this->getJson('/api/v1/capability-catalogue', tenantHeader($tenant))->assertOk();
});

// ---------------------------------------------------------------- REQ-SET-002

function b3dReadyCarrier(): Carrier
{
    $c = b3dCarrier();
    $auth = (string) Str::uuid();
    DB::table('insurer_regulatory_authorizations')->insert(['id' => $auth, 'carrier_id' => $c->id, 'status' => 'ACTIVE', 'effective_from' => '2026-01-01', 'authorization_reference' => 'TEST-AUTH', 'source' => 'DEMO', 'is_demo' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('insurer_authorized_branches')->insert(['id' => (string) Str::uuid(), 'authorization_id' => $auth, 'branch_code' => 'MOTOR', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    b3dProduct($c);
    DocumentIssuanceProfile::create(['carrier_id' => $c->id, 'issuance_mode' => 'MANUAL_UPLOAD', 'default_language' => 'BILINGUAL']);
    b3dActiveProfile($c, [['capability' => 'QUOTATION', 'mode' => 'MANUAL'], ['capability' => 'RATING', 'mode' => 'MANUAL_PREMIUM']]);

    return $c;
}

function b3dAdmin(array $perms = ['carrier_setup.*']): User
{
    $tenant = makeAuthTestTenant('b3d-set');
    $u = makeAuthTestUser($tenant, $perms);
    app(\App\Domain\Tenancy\TenantContext::class)->set($tenant->id);

    return $u;
}

it('REQ-SET-002 insurer lifecycle DRAFT→…→ACTIVE is gated by the 23-item checklist and maker-checker', function () {
    $svc = app(CarrierSetupService::class);
    $c = b3dReadyCarrier();
    $maker = b3dAdmin();
    $setup = $svc->open($c, $maker);
    expect($svc->evaluate($setup))->toHaveCount(23);

    $setup = $svc->transition($setup, 'submit_regulatory_review', $maker);
    $setup = $svc->transition($setup, 'regulatory_verified', $maker, 'Authorization checked');
    expect($setup->status)->toBe('CONFIGURATION');

    // manual items pending → testing refused
    expect(fn () => $svc->transition($setup, 'start_testing', $maker))->toThrow(\App\Interfaces\Http\Errors\ApiProblemException::class);

    $ev = collect($svc->evaluate($setup))->keyBy('code');
    expect($ev['TARIFFS']['status'])->toBe('NOT_APPLICABLE') // manual premium → configured prerequisite skipped (AOM)
        ->and($ev['INTEGRATIONS']['status'])->toBe('NOT_APPLICABLE')
        ->and($ev['DOCUMENTS']['status'])->toBe('COMPLETE')
        ->and($ev['REGULATORY_VERIFICATION']['source'])->toBe('AUTO');
    expect(fn () => $svc->attest($setup, 'PRODUCTS', 'COMPLETE', null, null, $maker))->toThrow(ValidationException::class);
    expect(fn () => $svc->attest($setup, 'LEGAL_INFORMATION', 'COMPLETE', null, null, $maker))->toThrow(ValidationException::class); // evidence required
    expect(fn () => $svc->attest($setup, 'COMMISSION', 'NOT_APPLICABLE', null, 'n/a', $maker))->toThrow(ValidationException::class);
    $svc->attest($setup, 'BROKER_AGREEMENTS', 'NOT_APPLICABLE', null, 'Direct writer, no brokers yet', $maker);
    foreach (['LEGAL_INFORMATION', 'ORGANIZATION_STRUCTURE', 'USERS_AND_ROLES', 'POLICY_RULES', 'CLAIMS_RULES', 'COMMISSION', 'PAYMENT_AND_SETTLEMENT', 'ACCOUNTING', 'NOTIFICATIONS', 'COMPLIANCE'] as $code) {
        $svc->attest($setup, $code, 'COMPLETE', 'DOC-'.$code, null, $maker);
    }
    $setup = $svc->transition($setup, 'start_testing', $maker);
    expect(fn () => $svc->transition($setup, 'submit_for_approval', $maker))->toThrow(\App\Interfaces\Http\Errors\ApiProblemException::class);
    $svc->attest($setup, 'TESTING', 'COMPLETE', 'SANDBOX-RUN-1', null, $maker);
    $setup = $svc->transition($setup, 'submit_for_approval', $maker);
    expect($setup->status)->toBe('READY_FOR_APPROVAL');

    expect(fn () => $svc->transition($setup, 'activate', $maker))->toThrow(\App\Interfaces\Http\Errors\ApiProblemException::class);
    $checker = b3dAdmin();
    // a second person still cannot activate directly without an APPROVED inbox request
    $req = \App\Models\ApprovalRequest::findOrFail($setup->approval_request_id);
    expect($req->action_code)->toBe('insurer_setup.activate')->and($req->status)->toBe('PENDING');
    expect(fn () => app(\App\Application\Approvals\ApprovalService::class)->approve($req, $maker))->toThrow(ValidationException::class);
    app(\App\Domain\Tenancy\TenantContext::class)->set(\App\Models\TenantMembership::where('user_id', $checker->id)->value('tenant_id'));
    app(\App\Application\Approvals\ApprovalService::class)->approve($req, $checker, 'Approved');
    expect($req->refresh()->status)->toBe('APPROVED');
    $setup = $setup->refresh();
    expect($setup->status)->toBe('ACTIVE')->and($svc->isOperational($c->id))->toBeTrue();
    expect(\App\Application\CarrierOperations\Setup\SetupChecklist::unmet($svc->evaluate($setup)))->toBe([]);
    expect($setup->activation_snapshot['checklist'])->toHaveCount(23);
    expect(DB::table('workflow_transition_history')->where('machine', 'insurer_setup')->where('subject_id', $setup->id)->count())->toBe(5);

    $setup = $svc->transition($setup, 'suspend', $checker, 'Regulator notice');
    expect($svc->isOperational($c->id))->toBeFalse();
    expect($svc->transition($setup, 'terminate', $checker, 'Licence withdrawn')->status)->toBe('TERMINATED');
});

it('REQ-SET-002 regulatory review cannot pass without an ACTIVE CIMA authorization; permissions enforced per event', function () {
    $svc = app(CarrierSetupService::class);
    $c = b3dCarrier();
    $manager = b3dAdmin(['carrier_setup.manage']);
    $setup = $svc->transition($svc->open($c, $manager), 'submit_regulatory_review', $manager);
    expect(fn () => $svc->transition($setup, 'regulatory_verified', $manager))->toThrow(\App\Interfaces\Http\Errors\ApiProblemException::class); // no approve perm
    $approver = b3dAdmin(['carrier_setup.approve']);
    try {
        $svc->transition($setup, 'regulatory_verified', $approver);
        $this->fail('expected guard failure');
    } catch (\App\Interfaces\Http\Errors\ApiProblemException $e) {
        expect($e->getMessage())->toContain('REGULATORY_VERIFICATION');
    }
});

it('REQ-SET-002 HTTP: open setup, read checklist and available events', function () {
    $tenant = makeAuthTestTenant('b3d-http');
    $u = makeAuthTestUser($tenant, ['carrier_setup.view', 'carrier_setup.manage']);
    $c = b3dCarrier();
    Passport::actingAs($u);
    $this->postJson("/api/v1/carriers/{$c->id}/setup", [], tenantHeader($tenant))->assertCreated()
        ->assertJsonPath('data.status', 'DRAFT')->assertJsonCount(23, 'meta.checklist')->assertJsonPath('meta.available_events', ['submit_regulatory_review']);
    $this->postJson("/api/v1/carriers/{$c->id}/setup/transitions", ['event' => 'regulatory_verified'], tenantHeader($tenant))->assertStatus(409);
    $this->putJson("/api/v1/carriers/{$c->id}/setup/checklist/policy_rules", ['status' => 'COMPLETE'], tenantHeader($tenant))->assertOk();
    $this->getJson("/api/v1/carriers/{$c->id}/setup", tenantHeader($tenant))->assertOk();
});

// ---------------------------------------------------------------- REQ-SET-003

function b3dBroker(?string $tenantId): Partner
{
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'B3D Courtage '.Str::random(4), 'status' => 'ACTIVE']);

    return Partner::create(['tenant_id' => $tenantId, 'party_id' => $party->id, 'type' => 'BROKER', 'status' => 'PENDING', 'compliance' => []]);
}

it('REQ-SET-003 broker lifecycle with 19-item checklist, maker-checker activation and partners.status sync', function () {
    $svc = app(PartnerSetupService::class);
    $maker = b3dAdmin(['partner_setup.*']);
    $p = b3dBroker(null);
    $setup = $svc->open($p, $maker);
    expect($svc->evaluate($setup))->toHaveCount(19);
    $setup = $svc->transition($setup, 'submit_review', $maker, 'Please review');
    expect(fn () => $svc->transition($setup, 'review_passed', $maker, 'Looks fine'))->toThrow(\App\Interfaces\Http\Errors\ApiProblemException::class);

    DB::table('partner_licences')->insert(['id' => (string) Str::uuid(), 'partner_id' => $p->id, 'authority' => 'MINFI', 'licence_type' => 'BROKER', 'licence_number' => 'L-1', 'status' => 'VERIFIED', 'expires_on' => '2099-01-01', 'created_at' => now(), 'updated_at' => now()]);
    $setup = $svc->transition($setup, 'review_passed', $maker, 'Licence verified');
    $carrier = b3dCarrier();
    $agreementId = (string) Str::uuid();
    DB::table('carrier_broker_agreements')->insert(['id' => $agreementId, 'carrier_id' => $carrier->id, 'partner_id' => $p->id, 'agreement_number' => 'CBA-'.Str::random(6),
        'effective_from' => '2026-01-01', 'effective_until' => '2099-01-01', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    expect(collect($svc->evaluate($setup))->keyBy('code')['PRODUCT_CATALOGUE']['status'])->toBe('PENDING'); // agreement without authorized lines
    DB::table('carrier_broker_agreement_products')->insert(['id' => (string) Str::uuid(), 'agreement_id' => $agreementId, 'line_code' => 'MOTOR', 'can_quote' => true, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    $ev = collect($svc->evaluate($setup))->keyBy('code');
    expect($ev['CARRIER_PORTFOLIO']['status'])->toBe('COMPLETE')->and($ev['PRODUCT_CATALOGUE']['status'])->toBe('COMPLETE');
    foreach ($svc->evaluate($setup) as $item) {
        if ($item['status'] === 'PENDING') {
            $item['not_applicable_allowed']
                ? $svc->attest($setup, $item['code'], 'NOT_APPLICABLE', null, 'Small broker', $maker)
                : $svc->attest($setup, $item['code'], 'COMPLETE', 'EV-'.$item['code'], null, $maker);
        }
    }
    $setup = $svc->transition($setup, 'start_testing', $maker, 'Configuration done');
    expect(fn () => $svc->transition($setup, 'activate', $maker, 'Self approval'))->toThrow(\App\Interfaces\Http\Errors\ApiProblemException::class);
    $checker = b3dAdmin(['partner_setup.*']);
    expect(fn () => $svc->transition($setup, 'activate', $checker, 'Bypass inbox'))->toThrow(\App\Interfaces\Http\Errors\ApiProblemException::class);
    $req = \App\Models\ApprovalRequest::findOrFail($setup->refresh()->approval_request_id);
    expect($req->action_code)->toBe('broker_setup.activate');
    app(\App\Application\Approvals\ApprovalService::class)->approve($req, $checker, 'Approved');
    $setup = $setup->refresh();
    expect($setup->status)->toBe('ACTIVE')->and($p->refresh()->status)->toBe('ACTIVE');
    expect(DB::table('partner_status_history')->where('partner_id', $p->id)->where('to_status', 'ACTIVE')->exists())->toBeTrue();
    $svc->transition($setup, 'suspend', b3dAdmin(['partner_setup.*']), 'Compliance hold');
    expect($p->refresh()->status)->toBe('SUSPENDED');
});

it('REQ-SET-003 only BROKER partners; tenant-scoped over HTTP', function () {
    $svc = app(PartnerSetupService::class);
    $party = Party::create(['type' => 'PERSON', 'display_name' => 'Agent', 'status' => 'ACTIVE']);
    $agent = Partner::create(['party_id' => $party->id, 'type' => 'AGENT', 'status' => 'PENDING', 'compliance' => []]);
    expect(fn () => $svc->open($agent, b3dUser()))->toThrow(ValidationException::class);

    $mine = makeAuthTestTenant('b3d-a');
    $other = makeAuthTestTenant('b3d-b');
    $u = makeAuthTestUser($mine, ['partner_setup.view', 'partner_setup.manage']);
    Passport::actingAs($u);
    $this->postJson('/api/v1/partners/'.b3dBroker($other->id)->id.'/setup', [], tenantHeader($mine))->assertNotFound();
    $this->postJson('/api/v1/partners/'.b3dBroker($mine->id)->id.'/setup', [], tenantHeader($mine))->assertCreated()->assertJsonCount(19, 'meta.checklist');
});

it('REQ-SET-003 rejection in the approval inbox returns the broker to CONFIGURATION', function () {
    $svc = app(PartnerSetupService::class);
    $maker = b3dAdmin(['partner_setup.*']);
    $p = b3dBroker(null);
    $setup = $svc->open($p, $maker);
    DB::table('partner_licences')->insert(['id' => (string) Str::uuid(), 'partner_id' => $p->id, 'authority' => 'MINFI', 'licence_type' => 'BROKER', 'licence_number' => 'L-2', 'status' => 'VERIFIED', 'created_at' => now(), 'updated_at' => now()]);
    $setup = $svc->transition($svc->transition($setup, 'submit_review', $maker, 'Please review'), 'review_passed', $maker, 'Licence verified');
    $carrier = b3dCarrier();
    $aid = (string) Str::uuid();
    DB::table('carrier_broker_agreements')->insert(['id' => $aid, 'carrier_id' => $carrier->id, 'partner_id' => $p->id, 'agreement_number' => 'CBA-'.Str::random(6), 'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('carrier_broker_agreement_products')->insert(['id' => (string) Str::uuid(), 'agreement_id' => $aid, 'line_code' => 'MOTOR', 'can_quote' => true, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    foreach ($svc->evaluate($setup) as $item) {
        if ($item['status'] === 'PENDING') {
            $item['not_applicable_allowed'] ? $svc->attest($setup, $item['code'], 'NOT_APPLICABLE', null, 'Small broker', $maker) : $svc->attest($setup, $item['code'], 'COMPLETE', 'EV', null, $maker);
        }
    }
    $setup = $svc->transition($setup, 'start_testing', $maker, 'Ready');
    $req = \App\Models\ApprovalRequest::findOrFail($setup->approval_request_id);
    app(\App\Application\Approvals\ApprovalService::class)->reject($req, b3dAdmin(['partner_setup.*']), 'Commission split missing evidence');
    expect($setup->refresh()->status)->toBe('CONFIGURATION')->and($setup->approval_request_id)->toBeNull();
    expect($req->refresh()->status)->toBe('REJECTED');
});
