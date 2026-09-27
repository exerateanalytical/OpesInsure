<?php

declare(strict_types=1);

/**
 * Security review 2026-09-27, item 2 (docs/SECURITY_REVIEW_MOBILE_2026-09-27.md): IDOR / tenant isolation on the
 * /mobile/{partner,agent,broker,carrier}/* routes. Changing an id in the URL must never return another tenant's,
 * another broker's/agent's or another carrier's clients, policies, claims, statements, documents, commissions or
 * withdrawals: the answer is 403/404 (or 422 for a mutating call refused before lookup), never 2xx and never 500.
 */

use App\Application\Identity\RoleCatalogue;
use App\Models\{CustomerAttribution, Partner, Role, Tenant, TenantMembership, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function sriHeaders(Tenant $tenant): array
{
    return ['X-Tenant-Id' => $tenant->id, 'Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()];
}

/** A client in $tenant attributed to $partner, with a policy, a claim, a customer-visible document and a commission accrual. */
function sriBook(Tenant $tenant, Partner $partner, User $recorder, string $tag): array
{
    $chain = makeMobileFinanceProposalChain($tenant);
    CustomerAttribution::create(['party_id' => $chain['party']->id, 'partner_id' => $partner->id, 'origin_type' => $partner->type, 'terms_version' => 't1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $recorder->id]);
    $policy = makeMobileTestPolicy($chain['proposal'], $tenant, $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-SRI-'.$tag, 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subMonth(), 'coverage_ends_at' => now()->addYear()]);
    $claim = makeMobileTestClaim($tenant, $policy, $chain['party'], ['claim_number' => 'CLM-SRI-'.$tag]);
    $customer = \App\Models\TenantCustomer::where(['tenant_id' => $tenant->id, 'party_id' => $chain['party']->id])->first() ?? makeMobileTestTenantCustomer($tenant, $chain['party']);
    $document = makeMobileTestDocument($tenant, $chain['party'], ['policy_id' => $policy->id, 'document_origin' => 'INSURER', 'document_type_code' => 'CERT_TEST', 'security_level' => 'PUBLIC_VERIFIABLE', 'status' => 'VALID', 'document_number' => 'DOC-SRI-'.$tag]);
    $accrual = makeMobileTestCommissionAccrual($tenant, $partner, $policy);

    return compact('chain', 'policy', 'claim', 'customer', 'document', 'accrual');
}

function sriCarrierUser(Tenant $t, string $role, ?string $carrierId): User
{
    $u = User::factory()->create(['status' => 'ACTIVE']);
    $m = TenantMembership::forceCreate(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE', 'carrier_id' => $carrierId]);
    $r = Role::firstOrCreate(['tenant_id' => $t->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $u;
}

// ---------------------------------------------------------------- agents

it('keeps an agent out of a rival agent\'s clients, documents, policies, claims, commissions and withdrawals (same tenant)', function () {
    $c = makeMobileCustomerFixture('+237670019300');
    $tenant = $c['tenant'];
    $agent = makeMobileAgentFixtureInTenant($tenant, '+237680019301');
    $rival = makeMobileAgentFixtureInTenant($tenant, '+237680019302');
    $mine = sriBook($tenant, $agent['partner'], $agent['user'], 'A-MINE');
    $theirs = sriBook($tenant, $rival['partner'], $rival['user'], 'A-THEIRS');
    $statement = makeMobileAgentStatement($tenant, $rival['partner']);
    $theirPayout = makeMobileAgentPayoutRequest($tenant, $rival['partner'], $statement);
    Passport::actingAs($agent['user']);
    $h = sriHeaders($tenant);

    $this->getJson('/api/v1/mobile/agent/clients/'.$mine['customer']->id, $h)->assertOk();
    expect($this->getJson('/api/v1/mobile/agent/clients/'.$theirs['customer']->id, $h)->status())->toBeIn([403, 404]);
    $this->getJson('/api/v1/mobile/partner/agent/clients/'.$theirs['customer']->id.'/documents', $h)->assertNotFound();

    expect(collect($this->getJson('/api/v1/mobile/partner/agent/policies', $h)->assertOk()->json('data'))->pluck('id')->all())->not->toContain($theirs['policy']->id)
        ->and(collect($this->getJson('/api/v1/mobile/partner/agent/claims', $h)->assertOk()->json('data'))->pluck('id')->all())->not->toContain($theirs['claim']->id)
        ->and(collect($this->getJson('/api/v1/mobile/agent/commissions', $h)->assertOk()->json('data'))->pluck('id')->all())->not->toContain($theirs['accrual']->id)
        ->and(collect($this->getJson('/api/v1/mobile/agent/withdrawals', $h)->assertOk()->json('data'))->pluck('id')->all())->not->toContain($theirPayout->id);
});

it('keeps an agent from another tenant out entirely: own tenant header gives 404, the victim tenant header gives 403', function () {
    $c = makeMobileCustomerFixture('+237670019310');
    $victim = makeMobileAgentFixtureInTenant($c['tenant'], '+237680019311');
    $book = sriBook($c['tenant'], $victim['partner'], $victim['user'], 'A-X');
    $attacker = makeMobileAgentFixture('+237680019312');
    Passport::actingAs($attacker['user']);

    $own = sriHeaders($attacker['tenant']);
    $this->getJson('/api/v1/mobile/agent/clients/'.$book['customer']->id, $own)->assertNotFound();
    $this->getJson('/api/v1/mobile/partner/agent/clients/'.$book['customer']->id.'/documents', $own)->assertNotFound();
    expect($this->getJson('/api/v1/mobile/partner/agent/policies', $own)->assertOk()->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/mobile/agent/commissions', $own)->assertOk()->json('data'))->toBe([]);

    // Pointing X-Tenant-Id at the victim's tenant is refused by ResolveTenant (no membership there).
    $this->getJson('/api/v1/mobile/agent/clients/'.$book['customer']->id, sriHeaders($c['tenant']))->assertForbidden();
    $this->getJson('/api/v1/mobile/agent/withdrawals', sriHeaders($c['tenant']))->assertForbidden();
});

// ---------------------------------------------------------------- brokers

it('keeps a broker out of another broker\'s statements, clients and client documents, across tenants', function () {
    $b1 = makeMobilePartnerFixture('BROKER', '+237670019320');
    $b2 = makeMobilePartnerFixture('BROKER', '+237670019321');
    $statement = makeMobileTestPartnerStatement($b1['tenant'], $b1['partner'], $b1['user']);
    $book = sriBook($b1['tenant'], $b1['partner'], $b1['user'], 'B-1');

    Passport::actingAs($b1['user']);
    $this->getJson('/api/v1/mobile/broker/statements/'.$statement->id, sriHeaders($b1['tenant']))->assertOk();
    $this->getJson('/api/v1/mobile/broker/clients/'.$book['customer']->id, sriHeaders($b1['tenant']))->assertOk();

    Passport::actingAs($b2['user']);
    $h = sriHeaders($b2['tenant']);
    $this->getJson('/api/v1/mobile/broker/statements/'.$statement->id, $h)->assertNotFound();
    $this->getJson('/api/v1/mobile/broker/clients/'.$book['customer']->id, $h)->assertNotFound();
    $this->getJson('/api/v1/mobile/partner/broker/clients/'.$book['customer']->id.'/documents', $h)->assertNotFound();
    expect(collect($this->getJson('/api/v1/mobile/partner/broker/policies', $h)->assertOk()->json('data'))->pluck('id')->all())->not->toContain($book['policy']->id)
        ->and(collect($this->getJson('/api/v1/mobile/partner/broker/claims', $h)->assertOk()->json('data'))->pluck('id')->all())->not->toContain($book['claim']->id);
    $this->getJson('/api/v1/mobile/broker/statements/'.$statement->id, sriHeaders($b1['tenant']))->assertForbidden();
});

it('keeps a broker out of a same-tenant rival partner\'s clients and documents (BookScope)', function () {
    $b1 = makeMobilePartnerFixture('BROKER', '+237670019330');
    $rivalParty = App\Models\Party::create(['type' => 'ORGANIZATION', 'display_name' => 'SRI rival', 'status' => 'ACTIVE']);
    $rival = Partner::create(['tenant_id' => $b1['tenant']->id, 'party_id' => $rivalParty->id, 'type' => 'BROKER', 'status' => 'ACTIVE']);
    $theirs = sriBook($b1['tenant'], $rival, $b1['user'], 'B-R');
    Passport::actingAs($b1['user']);
    $h = sriHeaders($b1['tenant']);

    $this->getJson('/api/v1/mobile/broker/clients/'.$theirs['customer']->id, $h)->assertNotFound();
    $this->getJson('/api/v1/mobile/partner/broker/clients/'.$theirs['customer']->id.'/documents', $h)->assertNotFound();
    expect(collect($this->getJson('/api/v1/mobile/broker/commission-accruals', $h)->assertOk()->json('data.data'))->pluck('id')->all())->not->toContain($theirs['accrual']->id);
});

// ---------------------------------------------------------------- carriers

it('keeps a carrier user out of another carrier\'s claims, claim evidence and settlements (CarrierScopeResolver)', function () {
    $t = Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'SRI '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    $books = [];
    foreach (['A', 'B'] as $tag) {
        $chain = makeMobileFinanceProposalChain($t);
        $policy = makeMobileTestPolicy($chain['proposal'], $t, $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-SRI-C'.$tag]);
        $claim = makeMobileTestClaim($t, $policy, $chain['party']);
        $doc = makeMobileTestDocument($t, $chain['party'], ['scan_status' => 'CLEAN']);
        $settlement = makeMobileTestSettlementBatch($t, $chain['carrier']->id, User::factory()->create(['status' => 'ACTIVE']));
        $books[$tag] = ['carrier' => (string) $chain['carrier']->id, 'claim' => (string) $claim->id, 'doc' => (string) $doc->id, 'settlement' => (string) $settlement->id];
    }
    $h = sriHeaders($t);

    Passport::actingAs(sriCarrierUser($t, 'CLAIMS_OFFICER', $books['A']['carrier']));
    $this->getJson('/api/v1/mobile/partner/carrier/claims/'.$books['A']['claim'], $h)->assertOk();
    $this->getJson('/api/v1/mobile/partner/carrier/claims/'.$books['B']['claim'], $h)->assertNotFound();
    $this->getJson('/api/v1/mobile/partner/carrier/claims/'.$books['B']['claim'].'/evidence', $h)->assertNotFound();
    expect($this->postJson('/api/v1/mobile/partner/carrier/claims/'.$books['B']['claim'].'/evidence/'.$books['B']['doc'].'/access', [], $h)->status())->toBeIn([403, 404]);
    // Own claim, but a document that is not that claim's evidence.
    expect($this->postJson('/api/v1/mobile/partner/carrier/claims/'.$books['A']['claim'].'/evidence/'.$books['B']['doc'].'/access', [], $h)->status())->toBeIn([403, 404]);

    Passport::actingAs(sriCarrierUser($t, 'FINANCE_OFFICER', $books['A']['carrier']));
    $this->getJson('/api/v1/mobile/carrier/settlements/'.$books['A']['settlement'], $h)->assertOk();
    expect($this->getJson('/api/v1/mobile/carrier/settlements/'.$books['B']['settlement'], $h)->status())->toBeIn([403, 404]);
});

// ---------------------------------------------------------------- sweep of every {id} route

it('answers every {id} partner route with 403/404/422 (never 2xx or 500) for another tenant\'s real ids and for unknown ids', function () {
    // Victim world in its own tenant.
    $victimCustomer = makeMobileCustomerFixture('+237670019340');
    $vt = $victimCustomer['tenant'];
    $victimAgent = makeMobileAgentFixtureInTenant($vt, '+237680019341');
    $book = sriBook($vt, $victimAgent['partner'], $victimAgent['user'], 'SWEEP');
    $victimStatement = makeMobileTestPartnerStatement($vt, $victimAgent['partner'], $victimAgent['user']);
    $victimSettlement = makeMobileTestSettlementBatch($vt, $book['chain']['carrier']->id, $victimAgent['user']);
    $victimBordereau = makeMobileTestBordereau($vt, $book['chain']['carrier']->id, $victimAgent['user']);
    $victimIds = [
        'customer' => $book['customer']->id, 'claim' => $book['claim']->id, 'document' => $book['document']->id, 'statement' => $victimStatement->id,
        'settlement' => $victimSettlement->id, 'bordereau' => $victimBordereau->id, 'id' => $book['chain']['proposal']->id, 'issuance' => $book['chain']['proposal']->id,
        'product' => (string) App\Models\InsuranceProduct::value('id'),
    ];

    // Attacker: an ACTIVE agent in another tenant holding every permission any of these routes asks for.
    $attacker = makeMobileAgentFixture('+237680019342');
    $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => preg_match('#^api/v1/mobile/(partner|agent|broker|carrier)/#', $r->uri()) && str_contains($r->uri(), '{'));
    $permissions = $routes->flatMap(fn ($r) => collect($r->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'))->map(fn ($m) => substr($m, 11)))->unique()->values()->all();
    $attacker['role']->update(['permissions' => array_values(array_unique([...$attacker['role']->permissions, ...$permissions]))]);
    Passport::actingAs($attacker['user']);
    expect($routes->count())->toBeGreaterThan(15);

    $bad = [];
    foreach ($routes as $route) {
        $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
        foreach (['victim', 'unknown'] as $mode) {
            $uri = preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $mode === 'victim' ? ($victimIds[$m[1]] ?? (string) Str::uuid()) : (string) Str::uuid(), $route->uri());
            Illuminate\Support\Facades\Cache::flush(); // throttle buckets are per user, shared across routes
            $status = $this->json($method, '/'.$uri, [], sriHeaders($attacker['tenant']))->status();
            if (! in_array($status, [403, 404, 422], true)) {
                $bad[] = "$method /$uri ($mode) => $status";
            }
        }
    }

    expect($bad)->toBe([]);
});
