<?php

declare(strict_types=1);

/**
 * ui:coverage STAFF_DESKTOP_NEEDED (2026-09-30): the admin partner-connection page links and revokes developers
 * (POST developer/clients/{client}/developers and .../{user}/revoke) — same permission, platform tenant only,
 * same PartnerDeveloperPortalService as the API.
 */

use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\IntegrationClients\Pages\ViewIntegrationClient;
use App\Models\{IntegrationClient, Role, Tenant, TenantMembership, User};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function icdTenant(string $type): Tenant
{
    return Tenant::create(['type' => $type, 'legal_name' => 'ICD '.$type.' '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
}

function icdStaff(Tenant $tenant, array $permissions): User
{
    $u = User::create(['full_name' => 'ICD '.Str::random(5), 'email' => 'icd-'.Str::random(6).'@example.test', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $u->id, 'role_code' => 'PLATFORM_ADMIN', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $tenant->id, 'code' => 'ICD-'.Str::random(8), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function icdAs(User $u, Tenant $tenant): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenant->id);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

function icdClient(): IntegrationClient
{
    return IntegrationClient::create(['name' => 'Partner API', 'client_id' => 'pa-'.Str::random(6), 'status' => 'ACTIVE', 'scopes' => [], 'environment' => 'sandbox', 'rate_limit_per_minute' => 60]);
}

it('links a developer and revokes the link from the partner connection page, audited through the API service', function () {
    $platform = icdTenant('PLATFORM');
    $client = icdClient();
    $dev = User::create(['full_name' => 'Dev Partner', 'email' => 'dev-'.Str::random(6).'@example.test', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    icdAs(icdStaff($platform, ['integrations.manage', 'integrations.revoke']), $platform);

    Livewire::test(ViewIntegrationClient::class, ['record' => $client->getKey()])
        ->assertActionVisible('linkDeveloper')->assertActionHidden('revokeDeveloper')
        ->callAction('linkDeveloper', ['user_id' => $dev->id, 'role' => 'OWNER'])->assertHasNoActionErrors();
    $link = DB::table('integration_client_developers')->where(['integration_client_id' => $client->id, 'user_id' => $dev->id])->first();
    expect($link->status)->toBe('ACTIVE')->and($link->role)->toBe('OWNER')
        ->and(DB::table('audit_log')->where('action', 'integration.developer.linked')->exists())->toBeTrue();

    Livewire::test(ViewIntegrationClient::class, ['record' => $client->getKey()])
        ->assertSee('Dev Partner')
        ->assertActionVisible('revokeDeveloper')
        ->callAction('revokeDeveloper', ['user_id' => $dev->id])->assertHasNoActionErrors();
    expect(DB::table('integration_client_developers')->where('id', $link->id)->value('status'))->toBe('REVOKED')
        ->and(DB::table('audit_log')->where('action', 'integration.developer.unlinked')->exists())->toBeTrue();
});

it('hides developer linking without the permission, and outside the platform tenant', function () {
    $platform = icdTenant('PLATFORM');
    $client = icdClient();
    icdAs(icdStaff($platform, ['integrations.manage']), $platform);
    DB::table('integration_client_developers')->insert(['id' => (string) Str::uuid(), 'integration_client_id' => $client->id, 'user_id' => icdStaff($platform, [])->id,
        'role' => 'DEVELOPER', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    Livewire::test(ViewIntegrationClient::class, ['record' => $client->getKey()])
        ->assertActionVisible('linkDeveloper')->assertActionHidden('revokeDeveloper'); // no integrations.revoke

    $this->flushSession();
    $carrier = icdTenant('CARRIER');
    icdAs(icdStaff($carrier, ['integrations.manage', 'integrations.revoke']), $carrier);
    Livewire::test(ViewIntegrationClient::class, ['record' => $client->getKey()])
        ->assertActionHidden('linkDeveloper')->assertActionHidden('revokeDeveloper');
});
