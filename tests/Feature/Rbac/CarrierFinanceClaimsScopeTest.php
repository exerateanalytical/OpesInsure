<?php

declare(strict_types=1);

/**
 * E9 (docs/spec/RBAC_MATRIX_BROKER_CARRIER.md §3a): an insurer's finance and claims officers get the matching carrier.*
 * reads, scoped to the carrier on their membership (CarrierScopeResolver), and never see another carrier's rows.
 */

use App\Application\Identity\RoleCatalogue;
use App\Models\{Role, Tenant, TenantMembership, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

function cfcUser(Tenant $t, string $role, ?string $carrierId): User
{
    $u = User::factory()->create(['status' => 'ACTIVE']);
    $m = TenantMembership::forceCreate(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE', 'carrier_id' => $carrierId]);
    $r = Role::firstOrCreate(['tenant_id' => $t->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $u;
}

beforeEach(function () {
    $this->t = Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'CFC '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    $this->books = [];
    foreach (['A', 'B'] as $tag) {
        $chain = makeMobileFinanceProposalChain($this->t);
        $policy = makeMobileTestPolicy($chain['proposal'], $this->t, $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-CFC-'.$tag]);
        $claim = makeMobileTestClaim($this->t, $policy, $chain['party']);
        $preparer = User::factory()->create(['status' => 'ACTIVE']);
        $settlement = makeMobileTestSettlementBatch($this->t, $chain['carrier']->id, $preparer);
        $this->books[$tag] = ['carrier' => (string) $chain['carrier']->id, 'claim' => (string) $claim->id, 'settlement' => (string) $settlement->id];
    }
    $this->h = ['X-Tenant-Id' => $this->t->id, 'Accept' => 'application/json'];
});

it('grants the finance and claims officers the matching carrier.* reads only', function () {
    expect(RoleCatalogue::defaultPermissions('FINANCE_OFFICER'))->toContain('carrier.dashboard.read', 'carrier.finance.read')->not->toContain('carrier.claims.read')
        ->and(RoleCatalogue::defaultPermissions('CLAIMS_OFFICER'))->toContain('carrier.dashboard.read', 'carrier.claims.read')->not->toContain('carrier.finance.read')
        ->and(RoleCatalogue::CARRIER_LINKABLE_ROLES)->toContain('FINANCE_OFFICER', 'CLAIMS_OFFICER', 'COMPLIANCE_ADMIN');
});

it('a finance officer linked to carrier A sees only carrier A settlements and no claims', function () {
    Passport::actingAs(cfcUser($this->t, 'FINANCE_OFFICER', $this->books['A']['carrier']));

    $ids = collect($this->getJson('/api/v1/mobile/carrier/settlements', $this->h)->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$this->books['A']['settlement']]);
    $this->getJson('/api/v1/mobile/carrier/dashboard', $this->h)->assertOk();
    $this->getJson('/api/v1/mobile/carrier/claims', $this->h)->assertForbidden();
});

it('a claims officer linked to carrier A sees only carrier A claims and no finance', function () {
    Passport::actingAs(cfcUser($this->t, 'CLAIMS_OFFICER', $this->books['A']['carrier']));

    $ids = collect($this->getJson('/api/v1/mobile/carrier/claims', $this->h)->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toContain($this->books['A']['claim'])->not->toContain($this->books['B']['claim']);
    $this->getJson('/api/v1/mobile/partner/carrier/claims/'.$this->books['B']['claim'], $this->h)->assertNotFound();
    $this->getJson('/api/v1/mobile/carrier/settlements', $this->h)->assertForbidden();
});

it('the sync command pushes the new grants to stored roles', function () {
    $role = Role::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->t->id, 'code' => 'FINANCE_OFFICER', 'permissions' => ['ledger.read'], 'is_system' => true]);
    $this->artisan('rbac:sync-role-permissions')->assertSuccessful();
    expect($role->fresh()->permissions)->toContain('carrier.finance.read', 'ledger.read');
});
