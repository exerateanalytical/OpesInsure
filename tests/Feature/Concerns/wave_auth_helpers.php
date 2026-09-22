<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Str;

if (! function_exists('makeAuthTestTenant')) {
    function makeAuthTestTenant(string $suffix = ''): Tenant
    {
        return Tenant::create([
            'id' => (string) Str::uuid(),
            'type' => 'CARRIER',
            'legal_name' => 'Auth Test Tenant '.($suffix !== '' ? $suffix : Str::random(6)),
            'slug' => 'auth-test-'.Str::lower(Str::random(10)),
            'status' => 'ACTIVE',
            'country_code' => 'CM',
            'currency' => 'XAF',
            'primary_locale' => 'en',
            'settings' => [],
            'activated_at' => now(),
        ]);
    }

    /**
     * A user with an ACTIVE membership in $tenant and exactly the given permissions
     * (never the '*' wildcard, so this reliably represents an "ordinary" role unless
     * $permissions itself is ['*']).
     */
    function makeAuthTestUser(Tenant $tenant, array $permissions, string $roleCode = 'TEST_ROLE', string $membershipStatus = 'ACTIVE'): User
    {
        $user = User::create([
            'id' => (string) Str::uuid(),
            'full_name' => 'Test User '.Str::random(6),
            'email' => Str::lower(Str::random(12)).'@example.test',
            'phone_e164' => '+2376'.random_int(10000000, 99999999),
            'password' => 'not-used-in-tests',
            'locale' => 'en',
            'status' => 'ACTIVE',
        ]);

        $membership = TenantMembership::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role_code' => $roleCode,
            'status' => $membershipStatus,
        ]);

        $role = Role::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'code' => $roleCode.'-'.Str::random(6),
            'permissions' => $permissions,
            'is_system' => false,
        ]);

        $membership->roles()->attach($role->id);

        return $user;
    }

    function makeAuthTestSystemAdmin(Tenant $tenant): User
    {
        return makeAuthTestUser($tenant, [], 'SYSTEM_ADMIN');
    }

    function tenantHeader(Tenant $tenant): array
    {
        return ['X-Tenant-Id' => $tenant->id];
    }
}
