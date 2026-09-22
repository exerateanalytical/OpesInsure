<?php

declare(strict_types=1);

use App\Models\Party;
use App\Models\PartyContact;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantCustomer;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

if (! function_exists('extractMobileOtpCode')) {
    /** Recovers the plaintext OTP from the faked Twilio SMS body — the only place it exists outside the hashed DB row. */
    function extractMobileOtpCode(): string
    {
        $code = null;

        Http::assertSent(function ($request) use (&$code) {
            if (! str_contains($request->url(), 'api.twilio.com')) {
                return false;
            }

            if (preg_match('/code is (\d{6})/', (string) $request['Body'], $m)) {
                $code = $m[1];
            }

            return true;
        });

        expect($code)->not->toBeNull();

        return $code;
    }
}

if (! function_exists('makeMobileTestUser')) {
    function makeMobileTestUser(string $phone = '+237670000000', string $status = 'ACTIVE'): User
    {
        return User::create([
            'full_name' => 'Mobile Test User',
            'phone_e164' => $phone,
            'password' => 'not-used-for-otp-login',
            'locale' => 'en',
            'status' => $status,
        ]);
    }

    /** @return array{0: Tenant, 1: TenantMembership} */
    function makeMobileTestWorkspace(User $user, array $permissions = ['some.permission'], string $roleCode = 'AGENT'): array
    {
        $tenant = Tenant::create([
            'type' => 'BROKER',
            'legal_name' => 'Mobile Test Broker '.Str::random(6),
            'status' => 'ACTIVE',
            'country_code' => 'CM',
            'currency' => 'XAF',
            'primary_locale' => 'en',
        ]);

        $membership = TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role_code' => $roleCode,
            'status' => 'ACTIVE',
        ]);

        $role = Role::create([
            'tenant_id' => $tenant->id,
            'code' => $roleCode.'-'.Str::random(6),
            'permissions' => $permissions,
            'is_system' => false,
        ]);

        $membership->roles()->attach($role->id);

        return [$tenant, $membership];
    }

    function linkMobileTestUserToParty(User $user, Tenant $tenant): TenantCustomer
    {
        $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Mobile Test Party', 'status' => 'ACTIVE']);
        PartyContact::create(['party_id' => $party->id, 'type' => 'PHONE', 'normalized_value' => $user->phone_e164, 'is_primary' => true]);

        return TenantCustomer::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'customer_number' => 'CUST-'.Str::random(6), 'status' => 'ACTIVE']);
    }
}
