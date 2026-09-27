<?php

declare(strict_types=1);

/**
 * Owner decision 2026-09-27 — docs/spec/RBAC_MATRIX_BROKER_CARRIER.md.
 *  (1) Matrix crawl: every /broker and /insurer page answers 200 to exactly the roles the matrix grants and 403 to
 *      the others, with the roles' governed default permissions (RoleCatalogue), never '*'.
 *  (2) Isolation: a broker admin sees only their own company's book inside a shared tenant and nothing of another
 *      tenant; broker staff see only the clients they recorded; a supervisor sees their team; carrier roles see
 *      only their carrier.
 */

use App\Application\Identity\RoleCatalogue;
use App\Application\Reporting\Dashboards\DashboardRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\CarrierBrokerAgreements\CarrierBrokerAgreementResource;
use App\Filament\Admin\Resources\Claims\ClaimResource;
use App\Filament\Admin\Resources\Memberships\MembershipResource;
use App\Filament\Admin\Resources\Policies\PolicyResource;
use App\Filament\Admin\Resources\Quotes\QuoteResource;
use App\Models\{Claim, Partner, Party, Policy, Role, Tenant, TenantMembership, User};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

/**
 * The matrix under test (section 2 and 3 of the spec): page => roles that get 200. Every other role of the panel
 * gets 403. Pages another audit is still adding to the insurer panel are covered by the spec, not pinned here.
 */
const RBACM_BROKER = [
    '/broker' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER'],
    '/broker/organisation-settings' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER'],
    '/broker/quotes' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF'],
    '/broker/policies' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER'],
    '/broker/claims' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER'],
    '/broker/agreements' => ['BROKER_ADMIN'],
    '/broker/commission-accruals' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF'],
    '/broker/carrier-settlements' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF'],
    '/broker/bordereaux/bordereaus' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF'],
    '/broker/memberships' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF'],
    '/broker/reports' => [],
];

const RBACM_INSURER = [
    '/insurer' => ['CARRIER_SUPER_ADMIN', 'CARRIER_ADMIN', 'CARRIER_STAFF', 'UNDERWRITER', 'SENIOR_UNDERWRITER', 'REINSURANCE_OFFICER', 'CUSTOMER_SERVICE', 'ADJUSTER'],
    '/insurer/policies' => ['CARRIER_SUPER_ADMIN', 'CARRIER_ADMIN', 'CARRIER_STAFF', 'UNDERWRITER', 'SENIOR_UNDERWRITER', 'REINSURANCE_OFFICER', 'CUSTOMER_SERVICE'],
    '/insurer/claims' => ['CARRIER_SUPER_ADMIN', 'CARRIER_ADMIN', 'CARRIER_STAFF', 'REINSURANCE_OFFICER', 'CUSTOMER_SERVICE', 'ADJUSTER'],
    '/insurer/agreements' => ['CARRIER_SUPER_ADMIN', 'CARRIER_ADMIN'],
    '/insurer/bordereaux/bordereaus' => ['CARRIER_SUPER_ADMIN', 'CARRIER_ADMIN', 'CARRIER_STAFF'],
    '/insurer/carrier-settlements' => ['CARRIER_SUPER_ADMIN', 'CARRIER_ADMIN', 'CARRIER_STAFF'],
    '/insurer/reports' => ['CARRIER_SUPER_ADMIN', 'CARRIER_ADMIN', 'REINSURANCE_OFFICER'],
    '/insurer/quotes' => ['CARRIER_SUPER_ADMIN', 'CARRIER_ADMIN', 'CARRIER_STAFF', 'UNDERWRITER', 'SENIOR_UNDERWRITER'],
    '/insurer/kyc' => ['CARRIER_SUPER_ADMIN', 'CARRIER_ADMIN'],
];

function rbacmTenant(string $type): Tenant
{
    return Tenant::create(['type' => $type, 'legal_name' => 'RBACM '.$type.' '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
}

/** A user holding $role with its governed default permissions (the system role row, as invitations create it). */
function rbacmUser(Tenant $t, string $role, array $membership = [], ?string $partyId = null): User
{
    $u = User::factory()->create(['status' => 'ACTIVE', 'party_id' => $partyId]);
    $m = TenantMembership::forceCreate(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE'] + $membership); // team_code is not mass-assignable
    $r = Role::firstOrCreate(['tenant_id' => $t->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $u;
}

function rbacmCompany(Tenant $t, string $name): Partner
{
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => $name, 'status' => 'ACTIVE']);

    return Partner::create(['tenant_id' => $t->id, 'party_id' => $party->id, 'type' => 'BROKER', 'status' => 'ACTIVE']);
}

/** A client of $partner recorded by $producer, with a quote, a proposal, a policy and a claim tagged $tag. */
function rbacmClient(Tenant $t, Partner $partner, User $producer, string $tag, ?string $carrierId = null): array
{
    $chain = makeMobileFinanceProposalChain($t);
    $chain['party']->update(['display_name' => 'CLIENT-'.$tag]);
    DB::table('customer_attributions')->insert(['id' => (string) Str::uuid(), 'party_id' => $chain['party']->id, 'partner_id' => $partner->id, 'origin_type' => 'BROKER',
        'terms_version' => '1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $producer->id, 'created_at' => now(), 'updated_at' => now()]);
    $policy = makeMobileTestPolicy($chain['proposal'], $t, $carrierId ?? $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-'.$tag]);
    $claim = Claim::create(['tenant_id' => $t->id, 'policy_id' => $policy->id, 'claimant_party_id' => $chain['party']->id, 'claim_number' => 'CLM-'.$tag, 'status' => 'REGISTERED',
        'loss_occurred_at' => now()->subDay(), 'loss_details' => ['description' => 'x'], 'currency' => 'XAF']);

    return ['party' => $chain['party'], 'quote_id' => $chain['proposal']->offer->quote_id, 'policy' => $policy, 'claim' => $claim, 'carrier_id' => $policy->carrier_id];
}

/** Runs $fn as $user inside the $panel panel of tenant $t (what the panel middleware sets up for a request). */
function rbacmAs(User $user, Tenant $t, string $panel, Closure $fn): mixed
{
    auth()->login($user);
    Filament::setCurrentPanel(Filament::getPanel($panel));
    app(TenantContext::class)->set($t->id);

    return $fn();
}

dataset('broker matrix roles', ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER']);
dataset('insurer matrix roles', ['CARRIER_SUPER_ADMIN', 'CARRIER_ADMIN', 'CARRIER_STAFF', 'UNDERWRITER', 'SENIOR_UNDERWRITER', 'REINSURANCE_OFFICER', 'CUSTOMER_SERVICE', 'ADJUSTER']);

it('broker panel: each role reaches exactly the pages of the matrix (200 permitted, 403 otherwise)', function (string $role) {
    $this->actingAs(rbacmUser(rbacmTenant('BROKER'), $role));
    $got = [];
    foreach (RBACM_BROKER as $url => $roles) {
        $got[$url] = $this->get($url)->getStatusCode();
    }
    $want = array_map(fn (array $roles) => in_array($role, $roles, true) ? 200 : 403, RBACM_BROKER);

    expect($got)->toBe($want);
})->with('broker matrix roles');

it('insurer panel: each role reaches exactly the pages of the matrix (200 permitted, 403 otherwise)', function (string $role) {
    $t = rbacmTenant('INSURER');
    $carrier = makeMobileFinanceProposalChain($t)['carrier'];
    $this->actingAs(rbacmUser($t, $role, ['carrier_id' => in_array($role, RoleCatalogue::CARRIER_ROLES, true) ? $carrier->id : null]));
    $got = [];
    foreach (RBACM_INSURER as $url => $roles) {
        $got[$url] = $this->get($url)->getStatusCode();
    }
    $want = array_map(fn (array $roles) => in_array($role, $roles, true) ? 200 : 403, RBACM_INSURER);

    expect($got)->toBe($want);
})->with('insurer matrix roles');

it('claims.view is the only claim-read permission and the owner-decision grants are in the catalogue', function () {
    foreach (RoleCatalogue::codes() as $role) {
        expect(RoleCatalogue::defaultPermissions($role))->not->toContain('claims.read');
    }
    expect(collect(config('permissions'))->contains(fn ($group) => is_array($group) && array_key_exists('claims.read', $group)))->toBeFalse();
    expect(RoleCatalogue::defaultPermissions('BROKER_ADMIN'))->toContain('distribution.agreements.view')
        ->and(RoleCatalogue::defaultPermissions('CARRIER_ADMIN'))->toContain('distribution.agreements.view')
        ->and(RoleCatalogue::defaultPermissions('CARRIER_SUPER_ADMIN'))->toContain('distribution.agreements.view');
});

it('rbac:sync-role-permissions renames claims.read and tops up stored roles without removing custom grants', function () {
    $t = rbacmTenant('BROKER');
    $custom = Role::create(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'code' => 'BROKER_ADMIN', 'permissions' => ['broker.portal.read', 'claims.read', 'my.custom.grant'], 'is_system' => false]);

    $this->artisan('rbac:sync-role-permissions')->assertSuccessful();
    $this->artisan('rbac:sync-role-permissions')->assertSuccessful(); // idempotent

    $perms = $custom->fresh()->permissions;
    expect($perms)->toContain('claims.view', 'my.custom.grant', 'distribution.agreements.view')->not->toContain('claims.read')
        ->and(count($perms))->toBe(count(array_unique($perms)));
});

describe('isolation inside a shared tenant', function () {
    beforeEach(function () {
        $this->t = rbacmTenant('PLATFORM');
        $this->other = rbacmTenant('PLATFORM');
        $this->a = rbacmCompany($this->t, 'Broker A');
        $this->b = rbacmCompany($this->t, 'Broker B');
        $pa = $this->a->party_id;
        $this->adminA = rbacmUser($this->t, 'BROKER_ADMIN', [], $pa);
        $this->supA = rbacmUser($this->t, 'BROKER_SUPERVISOR', ['team_code' => 'T1'], $pa);
        $this->staff1 = rbacmUser($this->t, 'BROKER_STAFF', ['team_code' => 'T1'], $pa);
        $this->staff2 = rbacmUser($this->t, 'BROKER_STAFF', ['team_code' => 'T2'], $pa);
        $this->adminB = rbacmUser($this->t, 'BROKER_ADMIN', [], $this->b->party_id);
        $this->c1 = rbacmClient($this->t, $this->a, $this->staff1, 'A1');
        $this->c2 = rbacmClient($this->t, $this->a, $this->staff2, 'A2');
        $this->cb = rbacmClient($this->t, $this->b, $this->adminB, 'B1');
        // The same company's client in ANOTHER tenant never shows up in this one.
        $this->cx = rbacmClient($this->other, $this->a, $this->staff1, 'X1');
    });

    $policies = fn (User $u, Tenant $t) => rbacmAs($u, $t, 'broker', fn () => PolicyResource::getEloquentQuery()->pluck('policy_number')->sort()->values()->all());

    it('a broker admin sees the whole company book and nothing of another broker or tenant', function () use ($policies) {
        expect($policies($this->adminA, $this->t))->toBe(['POL-A1', 'POL-A2'])
            ->and($policies($this->adminB, $this->t))->toBe(['POL-B1']);
        rbacmAs($this->adminA, $this->t, 'broker', function () {
            expect(ClaimResource::getEloquentQuery()->pluck('claim_number')->sort()->values()->all())->toBe(['CLM-A1', 'CLM-A2'])
                ->and(QuoteResource::getEloquentQuery()->pluck('id')->sort()->values()->all())->toBe(collect([$this->c1['quote_id'], $this->c2['quote_id']])->sort()->values()->all())
                ->and(MembershipResource::getEloquentQuery()->pluck('user_id')->sort()->values()->all())
                ->toBe(collect([$this->adminA, $this->supA, $this->staff1, $this->staff2])->pluck('id')->sort()->values()->all());
            // Dashboard tiles and their drill-downs use the same narrowed record set.
            $tiles = collect(app(DashboardRegistry::class)->data($this->t->id, 'broker')['tiles'])->keyBy('key');
            expect((int) $tiles['active_policies']['value'])->toBe(2)->and((int) $tiles['open_claims']['value'])->toBe(2);
        });
    });

    it('a record outside the book is not found on its detail page', function () {
        $this->actingAs($this->adminA)->get('/broker/policies/'.$this->c1['policy']->id)->assertOk();
        $this->actingAs($this->adminA)->get('/broker/policies/'.$this->cb['policy']->id)->assertNotFound();
        $this->flushSession(); // a new user needs a new session (AuthenticateSession)
        $this->actingAs($this->staff2)->get('/broker/claims/'.$this->c1['claim']->id)->assertNotFound();
    });

    it('broker staff see only the clients they recorded, not a colleague\'s', function () use ($policies) {
        expect($policies($this->staff1, $this->t))->toBe(['POL-A1'])
            ->and($policies($this->staff2, $this->t))->toBe(['POL-A2']);
        rbacmAs($this->staff1, $this->t, 'broker', fn () => expect(MembershipResource::getEloquentQuery()->pluck('user_id')->all())->toBe([$this->staff1->id]));
    });

    it('a supervisor sees their team only', function () use ($policies) {
        expect($policies($this->supA, $this->t))->toBe(['POL-A1']);
        rbacmAs($this->supA, $this->t, 'broker', fn () => expect(MembershipResource::getEloquentQuery()->pluck('user_id')->sort()->values()->all())
            ->toBe(collect([$this->supA, $this->staff1])->pluck('id')->sort()->values()->all()));
    });

    it('a broker user without a company link sees nothing in a shared tenant', function () use ($policies) {
        expect($policies(rbacmUser($this->t, 'BROKER_ADMIN'), $this->t))->toBe([]);
    });

    it('carrier agreements (and their commission terms) are the broker admin\'s own company only, in the portal and the API', function () {
        $carrier = $this->c1['carrier_id'];
        foreach ([[$this->a, 'AGR-A'], [$this->b, 'AGR-B']] as [$p, $n]) {
            DB::table('carrier_broker_agreements')->insert(['id' => (string) Str::uuid(), 'carrier_id' => $carrier, 'partner_id' => $p->id, 'agreement_number' => $n,
                'effective_from' => now()->toDateString(), 'status' => 'ACTIVE', 'territories' => '[]', 'channels' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        }
        rbacmAs($this->adminA, $this->t, 'broker', fn () => expect(CarrierBrokerAgreementResource::getEloquentQuery()->pluck('agreement_number')->all())->toBe(['AGR-A']));

        \Laravel\Passport\Passport::actingAs($this->adminA);
        $numbers = collect($this->getJson('/api/v1/carrier-broker-agreements', ['X-Tenant-Id' => $this->t->id])->assertOk()->json('data'))->pluck('agreement_number')->all();
        expect($numbers)->toBe(['AGR-A']);
    });
});

it('carrier roles see only their own carrier in a shared tenant; a carrier admin reaches their whole company book', function () {
    $t = rbacmTenant('PLATFORM');
    $broker = rbacmCompany($t, 'Broker');
    $producer = rbacmUser($t, 'BROKER_STAFF', [], $broker->party_id);
    $mine = rbacmClient($t, $broker, $producer, 'C1');
    $theirs = rbacmClient($t, $broker, $producer, 'C2');
    $admin = rbacmUser($t, 'CARRIER_ADMIN', ['carrier_id' => $mine['carrier_id']]);

    rbacmAs($admin, $t, 'insurer', function () {
        expect(PolicyResource::getEloquentQuery()->pluck('policy_number')->all())->toBe(['POL-C1'])
            ->and(ClaimResource::getEloquentQuery()->pluck('claim_number')->all())->toBe(['CLM-C1']);
    });
    $this->actingAs($admin)->get('/insurer/policies/'.$mine['policy']->id)->assertOk();
    $this->actingAs($admin)->get('/insurer/policies/'.$theirs['policy']->id)->assertNotFound();
});

