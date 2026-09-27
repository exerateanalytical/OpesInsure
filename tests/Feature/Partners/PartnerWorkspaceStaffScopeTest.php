<?php

declare(strict_types=1);

/**
 * Owner decision 2026-09-27 (docs/spec/RBAC_MATRIX_BROKER_CARRIER.md) in the /account partner workspace APIs:
 * broker staff see only the clients they recorded, a supervisor their team, the broker admin the whole company
 * book, and never another broker's clients. One rule for every page: PartnerWorkspaceScope::bookPartyIds → BookScope.
 */

use App\Application\Identity\RoleCatalogue;
use App\Models\{CustomerAttribution, Partner, Party, Role, Tenant, TenantCustomer, TenantMembership, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function pwsUser(Tenant $t, string $role, string $partyId, ?string $team = null): User
{
    $u = User::factory()->create(['status' => 'ACTIVE', 'party_id' => $partyId]);
    $m = TenantMembership::forceCreate(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE', 'team_code' => $team]);
    $r = Role::firstOrCreate(['tenant_id' => $t->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $u;
}

function pwsClient(Tenant $t, Partner $partner, User $recorder, string $tag): array
{
    $chain = makeMobileFinanceProposalChain($t);
    CustomerAttribution::create(['party_id' => $chain['party']->id, 'partner_id' => $partner->id, 'origin_type' => 'BROKER', 'terms_version' => 't1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $recorder->id]);
    $policy = makeMobileTestPolicy($chain['proposal'], $t, $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-'.$tag]);
    makeMobileTestClaim($t, $policy, $chain['party'], ['claim_number' => 'CLM-'.$tag]);
    $customer = TenantCustomer::where(['tenant_id' => $t->id, 'party_id' => $chain['party']->id])->first() ?? makeMobileTestTenantCustomer($t, $chain['party']);

    return ['customer' => $customer];
}

beforeEach(function () {
    $this->t = Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'PWS '.Str::random(4), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    $company = fn (string $n) => Partner::create(['tenant_id' => $this->t->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => $n, 'status' => 'ACTIVE'])->id, 'type' => 'BROKER', 'status' => 'ACTIVE']);
    $this->a = $company('Broker A');
    $this->b = $company('Broker B');
    $this->admin = pwsUser($this->t, 'BROKER_ADMIN', $this->a->party_id);
    $this->sup = pwsUser($this->t, 'BROKER_SUPERVISOR', $this->a->party_id, 'T1');
    $this->staff1 = pwsUser($this->t, 'BROKER_STAFF', $this->a->party_id, 'T1');
    $this->staff2 = pwsUser($this->t, 'BROKER_STAFF', $this->a->party_id, 'T2');
    $adminB = pwsUser($this->t, 'BROKER_ADMIN', $this->b->party_id);
    $this->c1 = pwsClient($this->t, $this->a, $this->staff1, 'A1');
    $this->c2 = pwsClient($this->t, $this->a, $this->staff2, 'A2');
    pwsClient($this->t, $this->b, $adminB, 'B1');
});

function pwsBook($test, User $u): array
{
    Passport::actingAs($u);
    $h = ['X-Tenant-Id' => $test->t->id];
    $col = fn (string $url, string $key) => collect($test->getJson($url, $h)->assertOk()->json('data'))->pluck($key)->sort()->values()->all();

    return [
        'policies' => $col('/api/v1/mobile/partner/broker/policies', 'policy_number'),
        'claims' => $col('/api/v1/mobile/partner/broker/claims', 'claim_number'),
        'clients' => $col('/api/v1/mobile/broker/clients', 'id'),
    ];
}

it('the broker admin sees the whole company book and nothing of another broker', function () {
    expect(pwsBook($this, $this->admin))->toBe(['policies' => ['POL-A1', 'POL-A2'], 'claims' => ['CLM-A1', 'CLM-A2'],
        'clients' => collect([$this->c1['customer']->id, $this->c2['customer']->id])->sort()->values()->all()]);
});

it('broker staff see only the clients they recorded; a colleague\'s client documents are not found', function () {
    expect(pwsBook($this, $this->staff1))->toBe(['policies' => ['POL-A1'], 'claims' => ['CLM-A1'], 'clients' => [$this->c1['customer']->id]]);
    $this->getJson('/api/v1/mobile/partner/broker/clients/'.$this->c2['customer']->id.'/documents', ['X-Tenant-Id' => $this->t->id])->assertNotFound();
    $this->getJson('/api/v1/mobile/partner/broker/clients/'.$this->c1['customer']->id.'/documents', ['X-Tenant-Id' => $this->t->id])->assertOk();
});

it('a supervisor sees their team\'s clients only', function () {
    expect(pwsBook($this, $this->sup))->toBe(['policies' => ['POL-A1'], 'claims' => ['CLM-A1'], 'clients' => [$this->c1['customer']->id]]);
});

function pwsFnol($test, User $u, array $client): \Illuminate\Testing\TestResponse
{
    Passport::actingAs($u);
    $party = $client['customer']->party_id;
    $policy = \App\Models\Policy::where('party_id', $party)->firstOrFail();
    $policy->forceFill(['coverage_starts_at' => now()->subMonths(6)])->save();

    return $test->postJson('/api/v1/mobile/partner/broker/claims', ['policy_id' => $policy->id, 'claimant_party_id' => $party,
        'loss_occurred_at' => now()->subDay()->toIso8601String(), 'loss_details' => ['description' => 'Windscreen broken'],
        'idempotency_key' => (string) Str::uuid()], ['X-Tenant-Id' => $test->t->id]);
}

it('broker staff file an assisted claim for their own client, not a colleague\'s; the admin may for any company client', function () {
    $res = pwsFnol($this, $this->staff1, $this->c1)->assertStatus(201);
    $snap = \Illuminate\Support\Facades\DB::table('claim_fnol_snapshots')->where('claim_id', $res->json('data.id'))->first();
    expect($snap->acting_partner_id)->toBe($this->a->id)->and($snap->reporter_user_id)->toBe($this->staff1->id);
    pwsFnol($this, $this->staff1, $this->c2)->assertStatus(422)->assertJsonValidationErrors('claimant_party_id');
    pwsFnol($this, $this->sup, $this->c2)->assertStatus(422);
    pwsFnol($this, $this->admin, $this->c2)->assertStatus(201);
});

it('a customer cannot use the broker-assisted FNOL endpoint', function () {
    $f = makeMobileCustomerFixture('+237670110299');
    Passport::actingAs($f['user']);
    $this->postJson('/api/v1/mobile/partner/broker/claims', [], tenantHeaderFor($f['tenant']))->assertStatus(403);
});

it('acting follows visibility: PartnerBook::contains uses BookScope::bookOf', function () {
    app(\App\Domain\Tenancy\TenantContext::class)->set($this->t->id);
    $book = app(\App\Application\Partners\PartnerBook::class);
    $p1 = $this->c1['customer']->party_id;
    $p2 = $this->c2['customer']->party_id;
    expect($book->contains($this->staff1, $p1))->toBeTrue()
        ->and($book->contains($this->staff1, $p2))->toBeFalse()
        ->and($book->contains($this->sup, $p2))->toBeFalse()
        ->and($book->contains($this->admin, $p2))->toBeTrue()
        ->and($book->contains($this->staff1, $this->a->party_id))->toBeTrue(); // own party stays actionable
    expect(fn () => $book->assertInBook($this->staff2, $p1))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
