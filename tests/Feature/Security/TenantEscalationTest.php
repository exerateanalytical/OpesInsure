<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_auth_helpers.php';

// Platform audit 2026-09-27, go-live blockers 7 and "cross-tenant revoke and status change".

function escalationTenant(string $type = 'BROKER'): Tenant
{
    return Tenant::create(['type' => $type, 'legal_name' => 'Esc '.uniqid(), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
}

it('refuses an invitation into a tenant other than the caller\'s current one', function () {
    $home = escalationTenant();
    $victim = escalationTenant('CARRIER');
    $admin = makeMobileTenantStaffUser($home, '+237670011001', 'BROKER_ADMIN');
    grantAllDefaults($admin, $home, 'BROKER_ADMIN', ['identity.invite']);

    Passport::actingAs($admin);
    $this->postJson('/api/v1/invitations', ['tenant_id' => $victim->id, 'recipient_phone_e164' => '+237670011002', 'role_code' => 'BROKER_STAFF'], tenantHeaderFor($home))
        ->assertStatus(403);
    expect(TenantInvitation::where('tenant_id', $victim->id)->exists())->toBeFalse();
});

it('does not let a claims manager (holding *) invite a platform admin', function () {
    $home = escalationTenant('CARRIER');
    $manager = makeMobileTenantStaffUser($home, '+237670011011', 'CLAIMS_MANAGER');
    grantAllDefaults($manager, $home, 'CLAIMS_MANAGER', ['*']);

    Passport::actingAs($manager);
    $this->postJson('/api/v1/invitations', ['recipient_phone_e164' => '+237670011012', 'role_code' => 'PLATFORM_ADMIN'], tenantHeaderFor($home))
        ->assertStatus(422)->assertJsonPath('errors.role_code.0', __('security.role_ceiling_platform'));
});

it('does not let broker staff invite a broker admin (role above their own permissions)', function () {
    $home = escalationTenant();
    $staff = makeMobileTenantStaffUser($home, '+237670011021', 'BROKER_STAFF');
    grantAllDefaults($staff, $home, 'BROKER_STAFF', ['identity.invite']);

    Passport::actingAs($staff);
    $this->postJson('/api/v1/invitations', ['recipient_phone_e164' => '+237670011022', 'role_code' => 'BROKER_ADMIN'], tenantHeaderFor($home))
        ->assertStatus(422);
});

it('lets a broker admin invite broker staff into their own organisation', function () {
    $home = escalationTenant();
    $admin = makeMobileTenantStaffUser($home, '+237670011031', 'BROKER_ADMIN');
    grantAllDefaults($admin, $home, 'BROKER_ADMIN', ['identity.invite']);

    Passport::actingAs($admin);
    $this->postJson('/api/v1/invitations', ['recipient_phone_e164' => '+237670011032', 'role_code' => 'BROKER_STAFF'], tenantHeaderFor($home))
        ->assertStatus(201);
});

it('404s when revoking a membership or invitation that belongs to another tenant', function () {
    $home = escalationTenant();
    $other = escalationTenant();
    $admin = makeMobileTenantStaffUser($home, '+237670011041', 'BROKER_ADMIN');
    grantAllDefaults($admin, $home, 'BROKER_ADMIN', ['identity.invite', 'identity.roles.manage']);
    $foreignMember = makeMobileTenantStaffUser($other, '+237670011042', 'BROKER_STAFF');
    $foreignMembership = TenantMembership::where('user_id', $foreignMember->id)->firstOrFail();
    $foreignInvite = TenantInvitation::create(['tenant_id' => $other->id, 'recipient_phone_e164' => '+237670011043', 'role_code' => 'BROKER_STAFF', 'token_hash' => hash('sha256', 'x'), 'status' => 'PENDING', 'expires_at' => now()->addDay(), 'invited_by' => $foreignMember->id]);

    Passport::actingAs($admin);
    $this->postJson("/api/v1/memberships/{$foreignMembership->id}/revoke", ['reason' => 'Cross tenant attempt'], tenantHeaderFor($home))->assertStatus(404);
    $this->deleteJson("/api/v1/invitations/{$foreignInvite->id}", [], tenantHeaderFor($home))->assertStatus(404);
    expect($foreignMembership->fresh()->status)->toBe('ACTIVE');
    expect($foreignInvite->fresh()->status)->toBe('PENDING');
});

it('only a platform admin can suspend an organisation', function () {
    $home = escalationTenant();
    $admin = makeMobileTenantStaffUser($home, '+237670011051', 'BROKER_ADMIN');
    grantAllDefaults($admin, $home, 'BROKER_ADMIN', ['tenant.status.manage']);

    Passport::actingAs($admin);
    $this->postJson("/api/v1/tenants/{$home->id}/status", ['status' => 'SUSPENDED', 'reason' => 'TEST', 'notes' => 'Trying to suspend my own org'], tenantHeaderFor($home))
        ->assertStatus(403);
    expect($home->fresh()->status)->toBe('ACTIVE');
});

/** Attach a role carrying the role's defaults plus $extra to the user's membership in $tenant. */
function grantAllDefaults($user, Tenant $tenant, string $roleCode, array $extra = []): void
{
    $m = TenantMembership::where(['user_id' => $user->id, 'tenant_id' => $tenant->id])->firstOrFail();
    $role = App\Models\Role::create(['tenant_id' => $tenant->id, 'code' => $roleCode.'-'.uniqid(), 'permissions' => array_values(array_unique([...App\Application\Identity\RoleCatalogue::defaultPermissions($roleCode), ...$extra])), 'is_system' => false]);
    $m->roles()->syncWithoutDetaching([$role->id]);
}
