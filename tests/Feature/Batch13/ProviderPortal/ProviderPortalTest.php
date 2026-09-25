<?php

declare(strict_types=1);

/** Agent E1 — REQ-PRV-003 provider portal: provider-scoped read API. */

use App\Application\Finance\Obligations\ObligationService;
use App\Application\Providers\Portal\ProviderScope;
use App\Application\Providers\ProviderNetworkService;
use App\Application\Providers\ProviderRegistry;
use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

const E1_PERMS = ['provider_portal.profile.view', 'provider_portal.network.view', 'provider_portal.tariffs.view', 'provider_portal.assignments.view',
    'provider_portal.preauth.view', 'provider_portal.claims.view', 'provider_portal.finance.view'];

function e1Provider(string $name, string $category = 'HEALTH'): object
{
    $reg = app(ProviderRegistry::class);
    $p = $reg->register(['category' => $category, 'name' => $name, 'provider_type_code' => 'CLINIC'], null);
    foreach (['APPLICATION', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE'] as $to) {
        $reg->transition($p->id, $to, null, null, null);
    }

    return $reg->find($p->id);
}

function e1Employee($tenant, object $provider, array $perms = E1_PERMS): User
{
    $person = Party::create(['type' => 'PERSON', 'display_name' => 'Front desk '.Str::random(4), 'status' => 'ACTIVE']);
    DB::table('party_relationships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'from_party_id' => $person->id, 'to_party_id' => $provider->party_id,
        'type' => 'EMPLOYED_BY', 'status' => 'ACTIVE', 'details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $u = makeAuthTestUser($tenant, $perms, 'FRONT_DESK');
    $u->update(['party_id' => $person->id]);

    return $u->refresh();
}

beforeEach(function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $f['tenant'];
    $this->h = ['X-Tenant-ID' => $this->tenant->id];
    $staff = makeAuthTestUser($this->tenant, []);
    $checker = makeAuthTestUser($this->tenant, []);
    $net = app(ProviderNetworkService::class);

    $this->clinic = e1Provider('Clinique du Littoral');
    $this->other = e1Provider('Hôpital Concurrent');
    $this->reg = app(ProviderRegistry::class);
    $fac = $this->reg->addFacility($this->clinic->id, ['code' => 'MAIN', 'name' => 'Main site', 'specialties' => ['GP']]);
    $this->svc = $net->addMedicalService(['code' => 'CONSULT_GP', 'name' => 'GP consultation', 'category_code' => 'OUTPATIENT']);
    $this->reg->addFacilityService($fac->id, $this->svc->id, 'GP');
    $this->reg->addFacility($this->other->id, ['code' => 'OTHER', 'name' => 'Other site']);

    $this->network = $net->createNetwork($this->tenant->id, ['code' => 'HEALTH_GOLD', 'name' => 'Gold network', 'network_type_code' => 'PREFERRED'], null);
    foreach ([$this->clinic, $this->other] as $i => $p) {
        $net->addMember($this->tenant->id, $this->network->id, ['provider_id' => $p->id, 'effective_from' => now()->subMonth()->toDateString()], null);
        $c = $net->createContract($this->tenant->id, $this->network->id, ['provider_id' => $p->id, 'contract_number' => 'HC-00'.$i, 'effective_from' => now()->subMonth()->toDateString()], null);
        $t = $net->draftTariff($this->tenant->id, $c->id, now()->subMonth()->toDateString(), 'XAF',
            [['medical_service_id' => $this->svc->id, 'price_minor' => 10000 + $i, 'contracted_price_minor' => 8000, 'copay_minor' => 1000, 'insurer_share_percent' => 80]], $staff->id);
        $net->approveTariff($this->tenant->id, $t->id, $checker->id);
    }

    $obl = app(ObligationService::class);
    $this->payable = $obl->create(['tenant_id' => $this->tenant->id, 'kind' => 'PAYABLE', 'type' => 'CLAIM', 'source_type' => 'test', 'source_id' => (string) Str::uuid(),
        'currency' => 'XAF', 'amount_minor' => 50000, 'due_at' => now()->addWeek(), 'creditor_type' => 'PARTY', 'creditor_id' => $this->clinic->party_id]);
    $obl->settle($this->payable->id, 20000, 'BANK-REF-1');
    $obl->create(['tenant_id' => $this->tenant->id, 'kind' => 'PAYABLE', 'type' => 'CLAIM', 'source_type' => 'test', 'source_id' => (string) Str::uuid(),
        'currency' => 'XAF', 'amount_minor' => 99999, 'due_at' => now()->addWeek(), 'creditor_type' => 'party', 'creditor_id' => $this->other->party_id]);

    $this->user = e1Employee($this->tenant, $this->clinic);
    Passport::actingAs($this->user, [], 'api');
});

it('REQ-PRV-003: an employee sees only their provider profile, facilities, services, network, contracts and tariffs', function () {
    $this->getJson('/api/v1/provider-portal/profile', $this->h)->assertOk()->assertJsonPath('data.id', $this->clinic->id);
    $fac = $this->getJson('/api/v1/provider-portal/facilities', $this->h)->assertOk()->json('data');
    expect(array_column($fac, 'code'))->toBe(['MAIN']);
    $this->getJson('/api/v1/provider-portal/services', $this->h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'CONSULT_GP');
    $this->getJson('/api/v1/provider-portal/network-memberships', $this->h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.network_code', 'HEALTH_GOLD');
    $contracts = $this->getJson('/api/v1/provider-portal/contracts', $this->h)->assertOk()->json('data');
    expect(array_column($contracts, 'contract_number'))->toBe(['HC-000']);
    $tariffs = $this->getJson('/api/v1/provider-portal/tariffs', $this->h)->assertOk()->json('data');
    expect($tariffs)->toHaveCount(1)->and($tariffs[0]['lines'][0]['price_minor'])->toBe(10000);
    $this->getJson('/api/v1/provider-portal/tariffs?contract_id='.$contracts[0]['id'], $this->h)->assertOk()->assertJsonCount(1, 'data');
});

it('REQ-PRV-003: statement and payments show only PAYABLE obligations owed to the provider', function () {
    $st = $this->getJson('/api/v1/provider-portal/statement', $this->h)->assertOk()->json('data');
    expect($st['obligations'])->toHaveCount(1)->and($st['obligations'][0]['id'])->toBe($this->payable->id)
        ->and($st['totals'])->toBe([['currency' => 'XAF', 'billed_minor' => 50000, 'paid_minor' => 20000, 'outstanding_minor' => 30000]]);
    $pay = $this->getJson('/api/v1/provider-portal/payments', $this->h)->assertOk()->json('data');
    expect($pay)->toHaveCount(1)->and($pay[0]['amount_minor'])->toBe(20000)->and($pay[0]['reference'])->toBe('BANK-REF-1');
});

it('REQ-PRV-003: assignments / preauths / claims lists are scoped and empty-safe when feeding tables are absent', function () {
    $this->getJson('/api/v1/provider-portal/assignments', $this->h)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/provider-portal/preauthorizations', $this->h)->assertOk()->assertJson(['data' => []]);
    $this->getJson('/api/v1/provider-portal/claims', $this->h)->assertOk()->assertJson(['data' => []]);

    // Once a feeding table exists (Batch 14 E3), rows light up scoped to the provider + tenant, internal notes stripped.
    // E3's real table is migrated since Wave A; FK checks are suspended for this read-only fixture (no policy / case graph needed).
    DB::statement("SET session_replication_role = 'replica'");
    foreach ([$this->clinic->id, $this->other->id] as $pid) {
        DB::table('health_preauthorizations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'provider_profile_id' => $pid,
            'preauth_number' => 'PA-'.Str::random(8), 'request_type' => 'OUTPATIENT', 'policy_id' => (string) Str::uuid(), 'member_ref' => 'M-1',
            'service_date' => now()->toDateString(), 'currency' => 'XAF', 'status' => 'APPROVED', 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::statement("SET session_replication_role = 'origin'");
    $rows = $this->getJson('/api/v1/provider-portal/preauthorizations?status=APPROVED', $this->h)->assertOk()->json('data');
    expect($rows)->toHaveCount(1)->and($rows[0]['provider_profile_id'])->toBe($this->clinic->id)->and($rows[0]['status'])->toBe('APPROVED');
});

it('REQ-PRV-003: ProviderScope rejects non-provider users, unknown providers, ended employment and missing permissions', function () {
    Passport::actingAs(makeAuthTestUser($this->tenant, E1_PERMS), [], 'api');
    $this->getJson('/api/v1/provider-portal/profile', $this->h)->assertForbidden()->assertJsonPath('code', 'NOT_PROVIDER_USER');

    Passport::actingAs($this->user, [], 'api');
    $this->getJson('/api/v1/provider-portal/profile', $this->h + ['X-Provider-ID' => $this->other->id])->assertNotFound();

    $limited = e1Employee($this->tenant, $this->clinic, ['provider_portal.profile.view']);
    Passport::actingAs($limited, [], 'api');
    $this->getJson('/api/v1/provider-portal/profile', $this->h)->assertOk();
    $this->getJson('/api/v1/provider-portal/statement', $this->h)->assertForbidden();

    DB::table('party_relationships')->where('from_party_id', $limited->party_id)->update(['valid_to' => now()->subDay()->toDateString()]);
    $this->getJson('/api/v1/provider-portal/profile', $this->h)->assertForbidden();

    // Acting for two providers requires an explicit selection.
    DB::table('party_relationships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'from_party_id' => $this->user->party_id, 'to_party_id' => $this->other->party_id,
        'type' => 'EMPLOYED_BY', 'status' => 'ACTIVE', 'details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    Passport::actingAs($this->user->refresh(), [], 'api');
    $this->getJson('/api/v1/provider-portal/profile', $this->h)->assertStatus(409)->assertJsonPath('code', 'PROVIDER_SELECTION_REQUIRED');
    $this->getJson('/api/v1/provider-portal/profile', $this->h + ['X-Provider-ID' => $this->other->id])->assertOk()->assertJsonPath('data.id', $this->other->id);
    expect(ProviderScope::actsFor($this->user, $this->other->id))->toBeTrue();

    // Terminated providers drop out of scope.
    $this->reg->transition($this->other->id, 'TERMINATED', 'Contract ended', null, null);
    $this->getJson('/api/v1/provider-portal/profile', $this->h)->assertOk()->assertJsonPath('data.id', $this->clinic->id);
});
