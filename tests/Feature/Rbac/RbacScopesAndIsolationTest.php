<?php

declare(strict_types=1);

/**
 * REQ-RBAC-001, REQ-RBAC-002, REQ-RBAC-003, REQ-RBAC-004, REQ-TEN-003
 */

use App\Application\Identity\Rbac\DataScope;
use App\Application\Identity\Rbac\DataScopeResolver;
use App\Application\Identity\Rbac\PermissionCatalogue;
use App\Application\Identity\RoleCatalogue;
use App\Domain\Tenancy\TenantContext;
use App\Models\PrivilegedAccessGrant;
use App\Models\Quote;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function rbacMember(User $user, $tenant, string $roleCode, ?array $permissions = null, array $extra = []): TenantMembership
{
    $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $roleCode, 'status' => 'ACTIVE'] + array_diff_key($extra, ['team_code' => 1]));
    if (isset($extra['team_code'])) {
        $m->forceFill(['team_code' => $extra['team_code']])->save();
    }
    $role = Role::create(['tenant_id' => $tenant->id, 'code' => $roleCode.'-'.Str::random(5), 'permissions' => $permissions ?? RoleCatalogue::defaultPermissions($roleCode), 'is_system' => false]);
    $m->roles()->attach($role->id);

    return $m;
}

function rbacUser(): User
{
    return User::create(['full_name' => 'RBAC '.Str::random(4), 'email' => Str::lower(Str::random(10)).'@example.test', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

function breakGlassGrant(User $user, $tenant, array $scope, array $overrides = []): PrivilegedAccessGrant
{
    $approver = rbacUser();

    return PrivilegedAccessGrant::create(array_merge([
        'user_id' => $user->id, 'tenant_id' => $tenant->id, 'purpose' => 'INCIDENT_RESPONSE', 'justification' => 'Investigating incident INC-1',
        'approved_by' => $approver->id, 'requested_by' => $approver->id, 'status' => 'APPROVED', 'scope' => $scope,
        'starts_at' => now()->subMinute(), 'expires_at' => now()->addHour(),
    ], $overrides));
}

// ---------- REQ-RBAC-001 ----------

it('REQ-RBAC-001 names every catalogued permission module.resource.action and syncs the permissions table', function () {
    foreach (array_keys(PermissionCatalogue::all()) as $code) {
        expect(PermissionCatalogue::isValidName($code))->toBeTrue("bad permission name: {$code}");
    }
    $n = PermissionCatalogue::sync();
    expect($n)->toBeGreaterThan(40);
    expect(PermissionCatalogue::sync())->toBe($n); // idempotent
    foreach (['documents.carrier.upload', 'documents.status.request', 'documents.status.approve', 'documents.templates.manage', 'documents.medical.read', 'documents.financial.read', 'documents.confidential.read', 'documents.regulatory.read', 'platform.break-glass.use'] as $code) {
        expect(DB::table('permissions')->where('code', $code)->exists())->toBeTrue($code);
    }
    expect(DB::table('permissions')->where('code', 'documents.templates.manage')->value('is_business_data'))->toBeFalse();
    expect(DB::table('permissions')->where('code', 'documents.medical.read')->value('is_business_data'))->toBeTrue();
});

// ---------- REQ-RBAC-003 ----------

it('REQ-RBAC-003 has one role catalogue covering BP III, SCF carrier, FRP provider and ESR actors', function () {
    foreach (['UNDERWRITER', 'SENIOR_UNDERWRITER', 'ADJUSTER', 'BRANCH_MANAGER', 'REINSURANCE_OFFICER', 'DEVELOPER', 'CARRIER_SUPER_ADMIN', 'CUSTOMER_SERVICE',
        'PROVIDER_ADMIN', 'PROVIDER_FRONT_DESK', 'PROVIDER_DOCTOR', 'PROVIDER_BILLING', 'PROVIDER_PHARMACY', 'PROVIDER_LAB', 'PROVIDER_FINANCE',
        'BROKER_SUPERVISOR', 'FINANCE_OFFICER', 'REGULATOR', 'SYSTEM_ADMIN', 'CUSTOMER', 'AGENT'] as $code) {
        expect(RoleCatalogue::codes())->toContain($code);
        expect(RoleCatalogue::SOURCES)->toHaveKey($code);
        expect(RoleCatalogue::defaultScope($code))->toBeInstanceOf(DataScope::class);
    }
    expect(array_diff(RoleCatalogue::INVITABLE, RoleCatalogue::codes()))->toBe([]);
    expect(RoleCatalogue::defaultPermissions('CUSTOMER'))->not->toContain('*');
    expect(RoleCatalogue::defaultPermissions('AGENT'))->not->toContain('*');
});

it('REQ-RBAC-003 seeds every catalogue role in the platform tenant without overwriting edits', function () {
    $this->seed(\Database\Seeders\DatabaseSeeder::class);
    $tenant = \App\Models\Tenant::where('slug', 'opesinsure-platform')->firstOrFail();
    foreach (RoleCatalogue::codes() as $code) {
        expect(Role::where('tenant_id', $tenant->id)->where('code', $code)->exists())->toBeTrue($code);
    }
    Role::where('tenant_id', $tenant->id)->where('code', 'UNDERWRITER')->update(['permissions' => json_encode(['edited.by.admin'])]);
    $this->seed(\Database\Seeders\DatabaseSeeder::class);
    expect(Role::where('tenant_id', $tenant->id)->where('code', 'UNDERWRITER')->first()->permissions)->toBe(['edited.by.admin']);
});

// ---------- REQ-RBAC-004 ----------

it('REQ-RBAC-004 SYSTEM_ADMIN keeps platform permissions but not business-data permissions, even with *', function () {
    $tenant = makeAuthTestTenant();
    $admin = rbacUser();
    rbacMember($admin, $tenant, 'SYSTEM_ADMIN', ['*']);
    app(TenantContext::class)->set($tenant->id);

    expect($admin->hasPermission('trust.privileged-access.approve'))->toBeTrue();
    expect($admin->hasPermission('integrations.manage'))->toBeTrue();
    expect($admin->hasPermission('documents.templates.manage'))->toBeTrue();
    foreach (['customers.read', 'policies.read', 'claims.view', 'carrier.claims.read', 'documents.medical.read', 'documents.financial.read', 'payout.approve'] as $p) {
        expect($admin->hasPermission($p))->toBeFalse($p);
    }
});

it('REQ-RBAC-004 an explicit business string on a SYSTEM_ADMIN role still does not grant business data', function () {
    $tenant = makeAuthTestTenant();
    $admin = rbacUser();
    rbacMember($admin, $tenant, 'SYSTEM_ADMIN', ['customers.read']);
    app(TenantContext::class)->set($tenant->id);
    expect($admin->hasPermission('customers.read'))->toBeFalse();
});

it('REQ-RBAC-004 a business role held alongside SYSTEM_ADMIN grants business data normally', function () {
    $tenant = makeAuthTestTenant();
    $admin = rbacUser();
    rbacMember($admin, $tenant, 'SYSTEM_ADMIN', ['*']);
    rbacMember($admin, $tenant, 'CLAIMS_MANAGER', ['claims.view']);
    app(TenantContext::class)->set($tenant->id);
    expect($admin->hasPermission('claims.view'))->toBeTrue();
    expect($admin->hasPermission('customers.read'))->toBeFalse();
});

it('REQ-RBAC-004 break-glass grants business data only with an approved in-window grant and audits every use', function () {
    $tenant = makeAuthTestTenant();
    $admin = rbacUser();
    rbacMember($admin, $tenant, 'SYSTEM_ADMIN', ['*']);
    app(TenantContext::class)->set($tenant->id);

    expect($admin->hasPermission('claims.view'))->toBeFalse();

    $grant = breakGlassGrant($admin, $tenant, ['claims.*']);
    expect($admin->hasPermission('claims.view'))->toBeTrue();
    expect($admin->hasPermission('customers.read'))->toBeFalse(); // outside grant scope
    expect(DB::table('privileged_access_events')->where('privileged_access_grant_id', $grant->id)->where('event_type', 'USED')->count())->toBeGreaterThan(0);
    expect(DB::table('audit_log')->where('action', 'privileged_access.break_glass')->where('subject_id', $grant->id)->exists())->toBeTrue();

    $grant->update(['status' => 'REVOKED', 'revoked_at' => now()]);
    expect($admin->hasPermission('claims.view'))->toBeFalse();

    breakGlassGrant($admin, $tenant, ['claims.view'], ['expires_at' => now()->subMinute(), 'starts_at' => now()->subHour()]);
    expect($admin->hasPermission('claims.view'))->toBeFalse(); // expired

    breakGlassGrant($admin, $tenant, ['claims.view'], ['status' => 'REQUESTED']);
    expect($admin->hasPermission('claims.view'))->toBeFalse(); // not approved
});

it('REQ-RBAC-004 break-glass needs the explicit break-glass permission', function () {
    $tenant = makeAuthTestTenant();
    $user = makeAuthTestUser($tenant, ['something.else']);
    breakGlassGrant($user, $tenant, ['claims.view']);
    app(TenantContext::class)->set($tenant->id);
    expect($user->hasPermission('claims.view'))->toBeFalse();
});

it('REQ-RBAC-004 a platform admin cannot list or read tenant business records over the API', function () {
    $f = makeMobileCustomerFixture('+237670100001');
    makeMobileTestTenantCustomer($f['tenant'], $f['party']);
    $admin = rbacUser();
    rbacMember($admin, $f['tenant'], 'SYSTEM_ADMIN', ['*']);
    Passport::actingAs($admin);
    $h = tenantHeaderFor($f['tenant']);

    $this->getJson('/api/v1/customers', $h)->assertStatus(403);
    $this->getJson('/api/v1/policies', $h)->assertStatus(403);
    $this->getJson("/api/v1/proposals/{$f['proposal']->id}", $h)->assertStatus(404);

    breakGlassGrant($admin, $f['tenant'], ['customers.read']);
    expect($this->getJson('/api/v1/customers', $h)->assertOk()->json('data.data'))->toHaveCount(1);
});

it('REQ-RBAC-004 keeps the admin panel open to SYSTEM_ADMIN', function () {
    $tenant = makeAuthTestTenant();
    $admin = rbacUser();
    rbacMember($admin, $tenant, 'SYSTEM_ADMIN', ['*']);
    expect($admin->canAccessPanel(app(\Filament\PanelRegistry::class)->get('admin')))->toBeTrue();
});

// ---------- REQ-RBAC-002 ----------

it('REQ-RBAC-002 defines nine scopes and resolves the widest one per tenant (roles.data_scope overrides)', function () {
    expect(DataScope::cases())->toHaveCount(9);
    $tenant = makeAuthTestTenant();
    $u = rbacUser();
    rbacMember($u, $tenant, 'AGENT');
    $resolver = app(DataScopeResolver::class);
    app(TenantContext::class)->set($tenant->id);
    expect($resolver->effectiveScope($u))->toBe(DataScope::ASSIGNED);

    $m = rbacMember($u, $tenant, 'BRANCH_MANAGER');
    expect($resolver->effectiveScope($u))->toBe(DataScope::BRANCH);

    $m->roles()->first()->forceFill(['data_scope' => 'TENANT'])->save();
    expect($resolver->effectiveScope($u))->toBe(DataScope::TENANT);
});

it('REQ-RBAC-002 narrows queries to own / assigned / team / branch / carrier / organization and fails closed', function () {
    $f = makeMobileCustomerFixture('+237670100002');
    $tenant = $f['tenant'];
    app(TenantContext::class)->set($tenant->id);
    $resolver = app(DataScopeResolver::class);
    $q = fn () => Quote::query();
    $other = Quote::create(['tenant_id' => $tenant->id, 'party_id' => \App\Models\Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Other', 'status' => 'ACTIVE'])->id, 'line_code' => 'AUTO', 'status' => 'RATED', 'currency' => 'XAF', 'risk_facts' => []]);

    // OWN
    expect($resolver->apply($q(), $f['user'], ['own' => 'party_id'])->pluck('id')->all())->toBe([$f['quote']->id]);
    expect($resolver->apply($q(), $f['user'], [])->count())->toBe(0); // column not declared → none

    // ASSIGNED / TEAM (party_id column used as a stand-in assignee column for the test)
    $agent = rbacUser();
    rbacMember($agent, $tenant, 'AGENT');
    expect($resolver->apply($q(), $agent, ['assigned' => 'party_id'])->count())->toBe(0);

    $sup = rbacUser();
    rbacMember($sup, $tenant, 'BROKER_SUPERVISOR', null, ['team_code' => 'T1']);
    $mate = rbacUser();
    rbacMember($mate, $tenant, 'BROKER_STAFF', null, ['team_code' => 'T1']);
    $sql = $resolver->apply($q(), $sup, ['assigned' => 'party_id'])->toRawSql();
    expect($sql)->toContain($mate->id)->toContain($sup->id);

    // BRANCH
    $branch = DB::table('tenant_branches')->insertGetId(['id' => $bid = (string) Str::uuid(), 'tenant_id' => $tenant->id, 'code' => 'DLA', 'name' => 'Douala', 'created_at' => now(), 'updated_at' => now()], 'id');
    $bm = rbacUser();
    rbacMember($bm, $tenant, 'BRANCH_MANAGER', null, ['branch_id' => $bid]);
    expect($resolver->apply($q(), $bm, ['branch' => 'party_id'])->toRawSql())->toContain($bid);
    expect($resolver->apply($q(), $bm, [])->count())->toBe(0);

    // CARRIER_RELATIONSHIP linked to one carrier
    $cs = rbacUser();
    rbacMember($cs, $tenant, 'CARRIER_STAFF', null, ['carrier_id' => $f['carrier']->id]);
    expect($resolver->apply($q(), $cs, ['carrier' => 'party_id'])->toRawSql())->toContain($f['carrier']->id);

    // TENANT staff see the whole tenant
    $staff = rbacUser();
    rbacMember($staff, $tenant, 'CLAIMS_MANAGER');
    expect($resolver->apply($q(), $staff)->count())->toBe(2);

    // PLATFORM and REGULATOR_READ see no business rows
    $sys = rbacUser();
    rbacMember($sys, $tenant, 'SYSTEM_ADMIN', ['*']);
    expect($resolver->apply($q(), $sys)->count())->toBe(0);
    $reg = rbacUser();
    rbacMember($reg, $tenant, 'REGULATOR');
    expect($resolver->apply($q(), $reg)->count())->toBe(0);
    expect($other->exists)->toBeTrue();
});

// ---------- REQ-TEN-003 ----------

it('REQ-TEN-003 never returns another tenant\'s rows, even to a TENANT-scope role', function () {
    $a = makeMobileCustomerFixture('+237670100003');
    $b = makeMobileCustomerFixture('+237670100004');
    $staff = rbacUser();
    rbacMember($staff, $a['tenant'], 'CLAIMS_MANAGER');
    app(TenantContext::class)->set($a['tenant']->id);

    $ids = app(DataScopeResolver::class)->apply(Quote::query(), $staff)->pluck('id')->all();
    expect($ids)->toContain($a['quote']->id)->not->toContain($b['quote']->id);
});

it('REQ-TEN-003 a role in tenant A grants nothing in tenant B', function () {
    $a = makeAuthTestTenant();
    $b = makeAuthTestTenant();
    $u = rbacUser();
    rbacMember($u, $a, 'CLAIMS_MANAGER', ['*']);
    rbacMember($u, $b, 'CUSTOMER');
    app(TenantContext::class)->set($b->id);
    expect($u->hasPermission('claims.view'))->toBeFalse();
    expect($u->hasPermission('customers.read'))->toBeFalse();
});

it('REQ-TEN-003 a SYSTEM_ADMIN membership in another tenant no longer lifts owner scoping', function () {
    [$a, $b] = [makeMobileCustomerFixture('+237670100005'), null];
    $b = makeMobileCustomerFixture('+237670100006');
    // Customer of A who is also SYSTEM_ADMIN in B.
    rbacMember($a['user'], $b['tenant'], 'SYSTEM_ADMIN', ['*']);
    makeMobileTestTenantCustomer($a['tenant'], $a['party']);
    $other = \App\Models\Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Other A', 'status' => 'ACTIVE']);
    makeMobileTestTenantCustomer($a['tenant'], $other);
    Passport::actingAs($a['user']);

    $rows = $this->getJson('/api/v1/customers', tenantHeaderFor($a['tenant']))->assertOk()->json('data.data');
    expect($rows)->toHaveCount(1);
});

it('REQ-TEN-003 rejects cross-tenant headers for a user without membership', function () {
    $a = makeMobileCustomerFixture('+237670100007');
    $b = makeMobileCustomerFixture('+237670100008');
    Passport::actingAs($a['user']);
    $res = $this->getJson("/api/v1/proposals/{$b['proposal']->id}", tenantHeaderFor($b['tenant']));
    expect($res->status())->toBeIn([403, 404]);
    $res = $this->getJson("/api/v1/proposals/{$b['proposal']->id}", tenantHeaderFor($a['tenant']));
    expect($res->status())->toBe(404);
});

it('REQ-RBAC-001 catalogues approvals/configuration/settings permissions as platform permissions granted to admins', function () {
    $tenant = makeAuthTestTenant();
    $admin = rbacUser();
    rbacMember($admin, $tenant, 'SYSTEM_ADMIN', ['*']);
    $pa = rbacUser();
    rbacMember($pa, $tenant, 'PLATFORM_ADMIN');
    $ca = rbacUser();
    rbacMember($ca, $tenant, 'CARRIER_ADMIN');
    app(TenantContext::class)->set($tenant->id);
    foreach (['approvals.inbox.view', 'approvals.decide', 'approvals.matrix.view', 'configuration.changes.manage', 'platform.settings.manage'] as $p) {
        expect(PermissionCatalogue::all())->toHaveKey($p);
        expect(PermissionCatalogue::isBusinessData($p))->toBeFalse($p);
        expect($admin->hasPermission($p))->toBeTrue($p);
        expect($pa->hasPermission($p))->toBeTrue($p);
    }
    expect($ca->hasPermission('approvals.decide'))->toBeTrue();
    expect($ca->hasPermission('platform.settings.manage'))->toBeFalse();
});
