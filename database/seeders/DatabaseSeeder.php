<?php

namespace Database\Seeders;

use App\Application\Identity\RoleCatalogue;
use App\Models\Partner;
use App\Models\Party;
use App\Models\PartyContact;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class DatabaseSeeder extends Seeder
{
    /**
     * One demo account per role that User::canAccessPanel() allows into the admin panel.
     */
    public const DEMO_ACCOUNTS = [
        ['role_code' => 'SYSTEM_ADMIN', 'label' => 'System admin', 'name' => 'Local Platform Administrator', 'email' => 'admin@opesinsure.local', 'phone' => '+237600000000'],
        ['role_code' => 'PLATFORM_ADMIN', 'label' => 'Platform admin', 'name' => 'Demo Platform Admin', 'email' => 'demo-platform-admin@opesinsure.local', 'phone' => '+237600000001'],
        ['role_code' => 'COMPLIANCE_ADMIN', 'label' => 'Compliance admin', 'name' => 'Demo Compliance Admin', 'email' => 'demo-compliance-admin@opesinsure.local', 'phone' => '+237600000002'],
        ['role_code' => 'FINANCE_ADMIN', 'label' => 'Finance admin', 'name' => 'Demo Finance Admin', 'email' => 'demo-finance-admin@opesinsure.local', 'phone' => '+237600000003'],
        ['role_code' => 'FINANCE_MANAGER', 'label' => 'Finance manager', 'name' => 'Demo Finance Manager', 'email' => 'demo-finance-manager@opesinsure.local', 'phone' => '+237600000004'],
        ['role_code' => 'CLAIMS_MANAGER', 'label' => 'Claims manager', 'name' => 'Demo Claims Manager', 'email' => 'demo-claims-manager@opesinsure.local', 'phone' => '+237600000005'],
        ['role_code' => 'CLAIMS_OFFICER', 'label' => 'Claims officer', 'name' => 'Demo Claims Officer', 'email' => 'demo-claims-officer@opesinsure.local', 'phone' => '+237600000006'],
        // BROKER_STAFF and AGENT are deliberately NOT given the '*' wildcard every
        // other demo role gets below — config/permissions.php's own
        // 'never_grant_to' list names both explicitly. Core quote/proposal/policy
        // creation needs no permission string at all (see routes/api.php), so
        // these only need the broker-specific back-office actions on top of that.
        ['role_code' => 'BROKER_STAFF', 'label' => 'Broker staff', 'name' => 'Demo Broker Staff', 'email' => 'demo-broker-staff@opesinsure.local', 'phone' => '+237600000007', 'permissions' => RoleCatalogue::BROKER_STAFF_PERMISSIONS],
        // Wave 12 Agent Mode grants: config/permissions.php's 'agent' category is the
        // deliberate exception to 'never_grant_to' — these exist specifically to be
        // granted to AGENT, scoped by AgentPartnerResolver ownership checks rather
        // than by withholding the permission itself.
        ['role_code' => 'AGENT', 'label' => 'Commercial agent', 'name' => 'Demo Commercial Agent', 'email' => 'demo-agent@opesinsure.local', 'phone' => '+237600000008', 'permissions' => RoleCatalogue::AGENT_PERMISSIONS],
    ];

    public function run(): void
    {
        // Official DGTCFM/MINFI register first, in every environment (owner: "seed this first, never delete").
        $this->call(CameroonInsuranceRegisterSeeder::class);
        $this->call(CanonicalEventSchemaSeeder::class);
        $this->call(NotificationTemplateSeeder::class);
        // Vehicle master data (makes, models, aliases, enums) supersedes the legacy VEHICLE_MAKES reference set.
        $this->call(VehicleMasterDataSeeder::class);
        $this->call(MobileOAuthClientSeeder::class);

        if (! app()->environment(['local', 'testing']) && ! config('demo.enabled')) {
            return;
        }

        $password = config('demo.local_admin_password') ?: (config('demo.enabled') ? config('demo.password') : null);

        if (blank($password)) {
            if (app()->environment('testing')) {
                $password = 'testing-only-password';
            } else {
                $this->command?->warn('LOCAL_ADMIN_PASSWORD is unset; local administrators were not seeded.');

                return;
            }
        }

        $tenant = Tenant::firstOrCreate(
            ['slug' => 'opesinsure-platform'],
            ['id' => (string) Str::uuid(), 'type' => 'PLATFORM', 'legal_name' => 'Opesware Technologies', 'trade_name' => 'OpesInsure', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => [], 'activated_at' => now()],
        );

        foreach (self::DEMO_ACCOUNTS as $account) {
            $isPersona = config('demo.enabled') && in_array($account['role_code'], (array) config('demo.persona_roles', []), true);
            $user = User::firstOrCreate(
                ['email' => $account['email']],
                ['id' => (string) Str::uuid(), 'full_name' => $account['name'], 'phone_e164' => $account['phone'], 'password' => Hash::make($isPersona && filled(config('demo.password')) ? config('demo.password') : $password), 'locale' => 'en', 'status' => 'ACTIVE', 'email_verified_at' => now(), 'phone_verified_at' => now()],
            );

            // Demo personas (agent/broker/...) keep the documented demo password
            // on re-seed. Admin/finance/compliance/claims passwords are never reset.
            if ($isPersona && filled(config('demo.password')) && ! Hash::check(config('demo.password'), (string) $user->password)) {
                $user->forceFill(['password' => Hash::make(config('demo.password'))])->save();
            }

            $membership = TenantMembership::firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $account['role_code']],
                ['id' => (string) Str::uuid(), 'status' => 'ACTIVE'],
            );

            // Explicit permission lists are re-applied on every seed so newly
            // introduced gates (e.g. broker.portal.read) reach existing rows;
            // wildcard staff roles are left alone once created.
            $role = isset($account['permissions'])
                ? Role::updateOrCreate(['tenant_id' => $tenant->id, 'code' => $account['role_code']], ['permissions' => $account['permissions'], 'is_system' => true])
                : Role::firstOrCreate(['tenant_id' => $tenant->id, 'code' => $account['role_code']], ['id' => (string) Str::uuid(), 'permissions' => ['*'], 'is_system' => true]);

            $membership->roles()->syncWithoutDetaching([$role->id]);

            // The web AGENT / BROKER_STAFF demo users can also sign in to the
            // mobile app (demo OTP), where every agent/broker screen resolves
            // the caller through users.party_id -> Partner. Give them that chain.
            if (in_array($account['role_code'], ['AGENT', 'BROKER_STAFF'], true)) {
                $this->ensureDemoPartner($user, $tenant, $account['role_code'] === 'AGENT' ? 'AGENT' : 'BROKER');
            }
        }

        // Insurer roles exist in the platform tenant even before anyone holds
        // them, so memberships created in the admin panel pick them up.
        foreach (RoleCatalogue::CARRIER_ROLES as $code) {
            Role::updateOrCreate(['tenant_id' => $tenant->id, 'code' => $code], ['permissions' => RoleCatalogue::defaultPermissions($code), 'is_system' => true]);
        }

        $this->call(DemoMobileAccountSeeder::class);
    }

    private function ensureDemoPartner(User $user, Tenant $tenant, string $type): void
    {
        $party = $user->party_id ? Party::find($user->party_id) : null;
        $party ??= Party::firstOrCreate(['display_name' => $user->full_name], ['id' => (string) Str::uuid(), 'type' => 'INDIVIDUAL', 'status' => 'ACTIVE']);
        PartyContact::firstOrCreate(
            ['party_id' => $party->id, 'type' => 'PHONE', 'normalized_value' => $user->phone_e164],
            ['id' => (string) Str::uuid(), 'is_primary' => true],
        );
        if ($user->party_id !== $party->id) {
            $user->forceFill(['party_id' => $party->id])->save();
        }
        Partner::firstOrCreate(['party_id' => $party->id], ['tenant_id' => $tenant->id, 'type' => $type, 'status' => 'ACTIVE', 'compliance' => []]);
    }
}
