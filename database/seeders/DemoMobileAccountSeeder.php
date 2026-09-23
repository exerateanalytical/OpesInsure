<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Party;
use App\Models\PartyContact;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo accounts for the personas that sign in through the MOBILE app.
 *
 * These differ from DatabaseSeeder::DEMO_ACCOUNTS (admin-panel staff) in one
 * way that matters: every mobile persona needs a real Party, a PHONE
 * PartyContact, and users.party_id set, because PartyResolver — and therefore
 * every ownership-scoped mobile endpoint — resolves the caller through it. A
 * user without that chain authenticates fine and then sees an empty wallet.
 */
final class DemoMobileAccountSeeder extends Seeder
{
    public const ACCOUNTS = [
        ['role_code' => 'CUSTOMER', 'label' => 'Customer', 'name' => 'Demo Customer', 'email' => 'demo-customer@opesinsure.local', 'phone' => '+237600000100', 'party_type' => 'INDIVIDUAL'],
        ['role_code' => 'AGENT', 'label' => 'Commercial agent (mobile)', 'name' => 'Demo Mobile Agent', 'email' => 'demo-mobile-agent@opesinsure.local', 'phone' => '+237600000101', 'party_type' => 'INDIVIDUAL'],
        ['role_code' => 'BROKER_STAFF', 'label' => 'Broker staff (mobile)', 'name' => 'Demo Mobile Broker', 'email' => 'demo-mobile-broker@opesinsure.local', 'phone' => '+237600000102', 'party_type' => 'INDIVIDUAL'],
        ['role_code' => 'CARRIER_STAFF', 'label' => 'Insurer staff (mobile)', 'name' => 'Demo Mobile Insurer', 'email' => 'demo-mobile-insurer@opesinsure.local', 'phone' => '+237600000103', 'party_type' => 'INDIVIDUAL'],
    ];

    /**
     * Phone numbers eligible for the fixed demo OTP. Kept beside the account
     * definitions so the two can never drift apart.
     *
     * @return list<string>
     */
    public static function phones(): array
    {
        return array_column(self::ACCOUNTS, 'phone');
    }

    public function run(): void
    {
        if (! config('demo.enabled')) {
            $this->command?->warn('demo.enabled is false; mobile demo accounts were not seeded.');

            return;
        }

        $tenant = Tenant::firstOrCreate(
            ['slug' => 'opesinsure-platform'],
            ['id' => (string) Str::uuid(), 'type' => 'PLATFORM', 'legal_name' => 'Opesware Technologies', 'trade_name' => 'OpesInsure', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => [], 'activated_at' => now()],
        );

        foreach (self::ACCOUNTS as $account) {
            $party = Party::firstOrCreate(
                ['display_name' => $account['name']],
                ['id' => (string) Str::uuid(), 'type' => $account['party_type'], 'status' => 'ACTIVE'],
            );

            PartyContact::firstOrCreate(
                ['party_id' => $party->id, 'type' => 'PHONE', 'normalized_value' => $account['phone']],
                ['id' => (string) Str::uuid(), 'is_primary' => true],
            );

            $user = User::firstOrCreate(
                ['email' => $account['email']],
                [
                    'id' => (string) Str::uuid(),
                    'full_name' => $account['name'],
                    'phone_e164' => $account['phone'],
                    'party_id' => $party->id,
                    'password' => Hash::make(config('demo.password')),
                    'locale' => 'en',
                    'status' => 'ACTIVE',
                    'email_verified_at' => now(),
                    'phone_verified_at' => now(),
                ],
            );

            // Existing rows from an earlier seed may predate the party link.
            if (blank($user->party_id)) {
                $user->forceFill(['party_id' => $party->id])->save();
            }

            $membership = TenantMembership::firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $account['role_code']],
                ['id' => (string) Str::uuid(), 'status' => 'ACTIVE'],
            );

            // updateOrCreate, not firstOrCreate: a role seeded before its
            // permission list existed must pick the list up on reseed.
            $role = Role::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $account['role_code']],
                ['permissions' => $this->permissionsFor($account['role_code']), 'is_system' => true],
            );

            if ($account['role_code'] === 'CUSTOMER') {
                \App\Models\TenantCustomer::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'party_id' => $party->id],
                    ['customer_number' => 'CUST-DEMO-0001', 'status' => 'ACTIVE'],
                );
            }

            $membership->roles()->syncWithoutDetaching([$role->id]);
        }
    }

    /** @return list<string> */
    private function permissionsFor(string $roleCode): array
    {
        return match ($roleCode) {
            // A customer holds no staff permissions at all — mobile customer
            // endpoints authorise by Party ownership, never by permission.
            // quotes.rate is the one staff-style gate on the customer purchase
            // path (POST quotes/{id}/rate); everything else is Party-owned.
            'CUSTOMER' => ['quotes.rate'],
            'AGENT' => ['agent.clients.read', 'agent.clients.manage', 'agent.commissions.read', 'agent.withdrawals.read', 'agent.withdrawals.request', 'agent.sync.read', 'agent.sync.retry', 'agent.sync.dispatch'],
            'BROKER_STAFF' => ['broker.bordereaux.manage', 'broker.bordereaux.submit', 'broker.renewals.manage', 'renewals.manage', 'quotes.rate'],
            'CARRIER_STAFF' => ['carrier.referrals.read', 'carrier.referrals.decide', 'carrier.issuance.read', 'carrier.claims.read'],
            default => [],
        };
    }
}
