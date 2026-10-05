<?php

declare(strict_types=1);

use App\Application\Identity\RoleCatalogue;
use App\Models\Claim;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_auth_helpers.php';

/*
 | Phase-1 fix S (owner-approved 2026-09-30): the mobile staff workspace (mobile/workspace/*) follows each caller's data
 | scope — carrier-linked users see their carrier only, branch roles their branch, adjusters their assigned claims — and
 | the five limited roles get a real workspace (workspace.read).
 */

function wsScopeTenant(): Tenant
{
    return Tenant::create(['type' => 'BROKER', 'legal_name' => 'WS Scope '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
}

/** One carrier's policy + claim in $tenant. @return array{carrier: string, policy: App\Models\Policy, claim: Claim} */
function wsScopeBook(Tenant $tenant, string $claimStatus = 'SUBMITTED', ?string $branch = null): array
{
    $chain = makeMobileFinanceProposalChain($tenant);
    $policy = makeMobileTestPolicy($chain['proposal'], $tenant, $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-'.Str::random(8)]);
    $claim = Claim::create(['tenant_id' => $tenant->id, 'policy_id' => $policy->id, 'claimant_party_id' => $chain['party']->id, 'claim_number' => 'CLM-'.Str::upper(Str::random(8)),
        'status' => $claimStatus, 'loss_occurred_at' => now()->subDay(), 'loss_details' => ['description' => 'Rear-end collision'], 'currency' => 'XAF', 'submitted_at' => now(), 'priority' => 'NORMAL']);
    if ($branch) {
        DB::table('policies')->where('id', $policy->id)->update(['branch_id' => $branch]);
        DB::table('claims')->where('id', $claim->id)->update(['branch_id' => $branch]);
    }

    return ['carrier' => $chain['carrier']->id, 'policy' => $policy, 'claim' => $claim];
}

function wsScopeBranch(Tenant $tenant, string $code): string
{
    $id = (string) Str::uuid();
    DB::table('tenant_branches')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'code' => $code, 'name' => 'Branch '.$code, 'status' => 'ACTIVE', 'address' => '{}', 'capabilities' => '[]', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function wsScopeStaff(Tenant $tenant, string $phone, string $role, array $membership = []): App\Models\User
{
    $user = makeMobileTenantStaffUser($tenant, $phone, $role);
    if ($membership) {
        DB::table('tenant_memberships')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->update($membership);
    }

    return $user;
}

it('grants the five limited roles workspace.read', function () {
    foreach (['CUSTOMER_SERVICE', 'REINSURANCE_OFFICER', 'BRANCH_MANAGER', 'CASHIER', 'ADJUSTER'] as $role) {
        expect(RoleCatalogue::defaultPermissions($role))->toContain('workspace.read');
        expect(collect(config('permissions'))->first(fn ($g) => is_array($g) && isset($g['workspace.read']))['workspace.read']['suggested_roles'])->toContain($role);
    }
});

it('narrows a carrier-linked claims officer to its own carrier (metrics, module, claims list and detail)', function () {
    $tenant = wsScopeTenant();
    $mine = wsScopeBook($tenant);
    $theirs = wsScopeBook($tenant);
    $officer = wsScopeStaff($tenant, '+237670031001', 'CLAIMS_OFFICER', ['carrier_id' => $mine['carrier']]);
    Passport::actingAs($officer);
    $h = tenantHeaderFor($tenant);

    $dash = $this->getJson('/api/v1/mobile/workspace/dashboard', $h)->assertOk();
    expect(collect($dash->json('data.metrics'))->firstWhere('key', 'claims')['value'])->toBe('1')
        ->and(collect($dash->json('data.metrics'))->firstWhere('key', 'policies')['value'])->toBe('1')
        ->and($dash->json('data.scope'))->toBe('carrier');

    $rows = $this->getJson('/api/v1/mobile/workspace/modules/claims', $h)->assertOk()->json('data.rows');
    expect(collect($rows)->pluck('Claim')->all())->toBe([$mine['claim']->claim_number]);

    $list = $this->getJson('/api/v1/mobile/workspace/claims', $h)->assertOk();
    expect(collect($list->json('data'))->pluck('id')->all())->toBe([$mine['claim']->id])
        ->and($list->json('meta.next_cursor'))->toBeNull();
    $this->getJson('/api/v1/mobile/workspace/claims/'.$theirs['claim']->id, $h)->assertNotFound();
    $this->postJson('/api/v1/mobile/workspace/claims/'.$theirs['claim']->id.'/transitions', ['to_status' => 'ACKNOWLEDGED'], $h)->assertNotFound();
});

it('keeps a tenant-wide claims manager seeing every carrier', function () {
    $tenant = wsScopeTenant();
    wsScopeBook($tenant);
    wsScopeBook($tenant);
    Passport::actingAs(wsScopeStaff($tenant, '+237670031011', 'CLAIMS_MANAGER'));

    $dash = $this->getJson('/api/v1/mobile/workspace/dashboard', tenantHeaderFor($tenant))->assertOk();
    expect(collect($dash->json('data.metrics'))->firstWhere('key', 'claims')['value'])->toBe('2')
        ->and($dash->json('data.scope'))->toBe('tenant');
});

it('narrows a branch manager to their branch and gives them customers, policies, claims and till sessions', function () {
    $tenant = wsScopeTenant();
    $north = wsScopeBranch($tenant, 'NTH');
    $south = wsScopeBranch($tenant, 'STH');
    $mine = wsScopeBook($tenant, 'SUBMITTED', $north);
    wsScopeBook($tenant, 'SUBMITTED', $south);
    $manager = wsScopeStaff($tenant, '+237670031021', 'BRANCH_MANAGER', ['branch_id' => $north]);
    Passport::actingAs($manager);
    $h = tenantHeaderFor($tenant);

    $dash = $this->getJson('/api/v1/mobile/workspace/dashboard', $h)->assertOk();
    expect(collect($dash->json('data.modules'))->pluck('key')->all())->toContain('customers', 'policies', 'claims', 'cashier')
        ->and(collect($dash->json('data.metrics'))->firstWhere('key', 'policies')['value'])->toBe('1')
        ->and($dash->json('data.scope'))->toBe('branch');
    $policies = $this->getJson('/api/v1/mobile/workspace/modules/policies', $h)->assertOk()->json('data.rows');
    expect(collect($policies)->pluck('Policy')->all())->toBe([$mine['policy']->policy_number]);
    $customers = $this->getJson('/api/v1/mobile/workspace/modules/customers', $h)->assertOk()->json('data.rows');
    expect($customers)->toBeArray();
});

it('shows an adjuster only the claims assigned to them, read-only', function () {
    $tenant = wsScopeTenant();
    $a = wsScopeBook($tenant);
    wsScopeBook($tenant);
    $adjuster = wsScopeStaff($tenant, '+237670031031', 'ADJUSTER');
    DB::table('claims')->where('id', $a['claim']->id)->update(['assigned_to' => $adjuster->id]);
    Passport::actingAs($adjuster);
    $h = tenantHeaderFor($tenant);

    $dash = $this->getJson('/api/v1/mobile/workspace/dashboard', $h)->assertOk();
    expect(collect($dash->json('data.modules'))->pluck('key')->all())->toBe(['claims'])
        ->and(collect($dash->json('data.metrics'))->firstWhere('key', 'claims')['value'])->toBe('1');
    expect(collect($this->getJson('/api/v1/mobile/workspace/claims', $h)->assertOk()->json('data'))->pluck('id')->all())->toBe([$a['claim']->id]);
    expect($this->getJson('/api/v1/mobile/workspace/claims/'.$a['claim']->id, $h)->assertOk()->json('data.actions'))->toBe([]);
    $this->postJson('/api/v1/mobile/workspace/claims/'.$a['claim']->id.'/transitions', ['to_status' => 'ACKNOWLEDGED'], $h)->assertForbidden();
});

it('gives a cashier the till modules of their own branch only', function () {
    $tenant = wsScopeTenant();
    $north = wsScopeBranch($tenant, 'NTH');
    $south = wsScopeBranch($tenant, 'STH');
    $cashier = wsScopeStaff($tenant, '+237670031041', 'CASHIER', ['branch_id' => $north]);
    $other = wsScopeStaff($tenant, '+237670031042', 'CASHIER', ['branch_id' => $south]);
    foreach ([[$cashier, $north], [$other, $south]] as [$u, $branch]) {
        DB::table('cashier_sessions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch, 'cashier_user_id' => $u->id, 'currency' => 'XAF', 'opening_float_minor' => 500000, 'status' => 'OPEN', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
    Passport::actingAs($cashier);
    $h = tenantHeaderFor($tenant);

    $dash = $this->getJson('/api/v1/mobile/workspace/dashboard', $h)->assertOk();
    expect(collect($dash->json('data.modules'))->pluck('key')->all())->toBe(['cashier', 'collections'])
        ->and(collect($dash->json('data.metrics'))->firstWhere('key', 'cashier_open')['value'])->toBe('1')
        ->and(collect($dash->json('data.metrics'))->pluck('key')->all())->not->toContain('policies', 'claims', 'payments');
    expect($this->getJson('/api/v1/mobile/workspace/modules/cashier', $h)->assertOk()->json('data.rows'))->toHaveCount(1);
    $this->getJson('/api/v1/mobile/workspace/modules/policies', $h)->assertForbidden();
});

it('gives customer service and reinsurance their module sets, and refuses an unlinked insurer role outside an insurer tenant', function () {
    $tenant = wsScopeTenant();
    $book = wsScopeBook($tenant);
    $cs = wsScopeStaff($tenant, '+237670031051', 'CUSTOMER_SERVICE', ['carrier_id' => $book['carrier']]);
    Passport::actingAs($cs);
    $keys = collect($this->getJson('/api/v1/mobile/workspace/dashboard', tenantHeaderFor($tenant))->assertOk()->json('data.modules'))->pluck('key')->all();
    expect($keys)->toBe(['policies', 'claims', 'customers', 'support']);

    $ri = wsScopeStaff($tenant, '+237670031052', 'REINSURANCE_OFFICER');
    Passport::actingAs($ri);
    $dash = $this->getJson('/api/v1/mobile/workspace/dashboard', tenantHeaderFor($tenant))->assertOk();
    expect(collect($dash->json('data.modules'))->pluck('key')->all())->toContain('treaties', 'cessions')
        ->and($dash->json('data.scope'))->toBe('none')
        ->and(collect($dash->json('data.metrics'))->firstWhere('key', 'claims')['value'])->toBe('0');
});

it('lets a claims officer work a claim: assign to me, move status, and propose a decision only at decision stage', function () {
    $tenant = wsScopeTenant();
    $book = wsScopeBook($tenant);
    $officer = wsScopeStaff($tenant, '+237670031061', 'CLAIMS_OFFICER');
    Passport::actingAs($officer);
    $h = tenantHeaderFor($tenant);
    $url = '/api/v1/mobile/workspace/claims/'.$book['claim']->id;

    $detail = $this->getJson($url, $h)->assertOk();
    expect($detail->json('data.actions'))->toContain('assign_to_me', 'transition')->not->toContain('propose_decision')
        ->and(collect($detail->json('data.transitions'))->pluck('to_status')->all())->toBe(['ACKNOWLEDGED']);

    $this->postJson($url.'/assign-to-me', [], $h)->assertOk()->assertJsonPath('data.assigned_to_me', true);
    $this->postJson($url.'/transitions', ['to_status' => 'ACKNOWLEDGED'], $h)->assertOk()->assertJsonPath('data.status', 'ACKNOWLEDGED');
    $this->postJson($url.'/transitions', ['to_status' => 'CLOSED'], $h)->assertStatus(422);
    $this->postJson($url.'/decisions', ['decision' => 'DECLINE', 'reason_code' => 'NOT_COVERED', 'rationale' => 'Loss outside the insured perils list.'], $h)->assertStatus(422);
    expect(DB::table('claim_events')->where('claim_id', $book['claim']->id)->where('to_status', 'ACKNOWLEDGED')->exists())->toBeTrue();
});

it('filters and pages the claims list with a cursor', function () {
    $tenant = wsScopeTenant();
    foreach (range(1, 3) as $i) {
        wsScopeBook($tenant);
    }
    wsScopeBook($tenant, 'CLOSED');
    Passport::actingAs(wsScopeStaff($tenant, '+237670031071', 'CLAIMS_MANAGER'));
    $h = tenantHeaderFor($tenant);

    $first = $this->getJson('/api/v1/mobile/workspace/claims?status=SUBMITTED&limit=2', $h)->assertOk();
    expect($first->json('data'))->toHaveCount(2)->and($first->json('meta.next_cursor'))->not->toBeNull();
    $second = $this->getJson('/api/v1/mobile/workspace/claims?status=SUBMITTED&limit=2&cursor='.$first->json('meta.next_cursor'), $h)->assertOk();
    expect($second->json('data'))->toHaveCount(1)->and($second->json('meta.next_cursor'))->toBeNull();
    expect(array_intersect(collect($first->json('data'))->pluck('id')->all(), collect($second->json('data'))->pluck('id')->all()))->toBe([]);
    $this->getJson('/api/v1/mobile/workspace/claims?cursor=bogus', $h)->assertStatus(422);

    $module = $this->getJson('/api/v1/mobile/workspace/modules/claims?limit=3', $h)->assertOk();
    expect($module->json('data.rows'))->toHaveCount(3)->and($module->json('meta.next_cursor'))->toBe($module->json('data.next_cursor'))->not->toBeNull();
});

it('localizes module titles, column headers and statuses in French', function () {
    $tenant = wsScopeTenant();
    wsScopeBook($tenant);
    Passport::actingAs(wsScopeStaff($tenant, '+237670031081', 'CLAIMS_MANAGER'));
    $h = tenantHeaderFor($tenant) + ['Accept-Language' => 'fr'];

    $module = $this->getJson('/api/v1/mobile/workspace/modules/claims', $h)->assertOk();
    expect($module->json('data.title'))->toBe('Sinistres')
        ->and($module->json('data.columns'))->toBe(['Sinistre', 'Déclarant', 'Statut', 'Provision (FCFA)', 'Déclaré le'])
        ->and($module->json('data.column_keys'))->toBe(['claim', 'claimant', 'status', 'reserve', 'submitted'])
        ->and($module->json('data.rows.0.Statut'))->toBe('Déclaré');
    expect($this->getJson('/api/v1/mobile/workspace/dashboard', $h)->assertOk()->json('data.title'))->toBe('Espace responsable sinistres');
});
