<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_auth_helpers.php';

// Security fixes 2026-09-29 found while building the staff screens.

function hardeningTenant(string $type = 'BROKER'): Tenant
{
    return Tenant::create(['type' => $type, 'legal_name' => 'Hard '.uniqid(), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
}

function hardeningGrant($user, Tenant $tenant, array $permissions): void
{
    $m = App\Models\TenantMembership::where(['user_id' => $user->id, 'tenant_id' => $tenant->id])->firstOrFail();
    $role = App\Models\Role::create(['tenant_id' => $tenant->id, 'code' => 'HARD-'.uniqid(), 'permissions' => $permissions, 'is_system' => false]);
    $m->roles()->syncWithoutDetaching([$role->id]);
}

it('refuses organisation creation to anyone but a platform admin (no self-made PLATFORM tenant)', function () {
    $home = hardeningTenant();
    $staff = makeMobileTenantStaffUser($home, '+237670019001', 'BROKER_ADMIN');

    Passport::actingAs($staff);
    $this->postJson('/api/v1/tenants', ['type' => 'PLATFORM', 'legal_name' => 'Rogue Platform', 'slug' => 'rogue-platform'], tenantHeaderFor($home))
        ->assertStatus(403);
    expect(Tenant::where('slug', 'rogue-platform')->exists())->toBeFalse();
});

it('lets a platform admin create an organisation', function () {
    $platform = hardeningTenant('PLATFORM');
    $admin = makeMobileTenantStaffUser($platform, '+237670019002', 'PLATFORM_ADMIN');

    Passport::actingAs($admin);
    $this->postJson('/api/v1/tenants', ['type' => 'BROKER', 'legal_name' => 'New Broker SARL', 'slug' => 'new-broker-sarl', 'primary_locale' => 'fr'], tenantHeaderFor($platform))
        ->assertCreated();
});

it('requires fulfilments.manage to create a fulfilment order', function () {
    $home = hardeningTenant();
    $staff = makeMobileTenantStaffUser($home, '+237670019003', 'BROKER_STAFF');

    Passport::actingAs($staff);
    $this->postJson('/api/v1/fulfilment-orders', [], tenantHeaderFor($home))->assertStatus(403);
});

it('refuses to reverse another tenant\'s journal (404)', function () {
    $home = hardeningTenant();
    $other = hardeningTenant();
    $staff = makeMobileTenantStaffUser($home, '+237670019004', 'FINANCE_MANAGER');
    hardeningGrant($staff, $home, ['ledger.reverse']);
    $journal = (string) Str::uuid();
    DB::table('journals')->insert(['id' => $journal, 'tenant_id' => $other->id, 'reference_type' => 'test', 'reference_id' => (string) Str::uuid(), 'currency' => 'XAF', 'correlation_id' => (string) Str::uuid(), 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    Passport::actingAs($staff);
    $this->postJson("/api/v1/ledger/journals/{$journal}/reverse", ['reason_code' => 'ERROR', 'notes' => 'Reversal attempt on a foreign journal for testing.'], tenantHeaderFor($home))
        ->assertNotFound();
});
