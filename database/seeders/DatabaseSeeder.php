<?php

namespace Database\Seeders;

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
        ['role_code' => 'BROKER_STAFF', 'label' => 'Broker staff', 'name' => 'Demo Broker Staff', 'email' => 'demo-broker-staff@opesinsure.local', 'phone' => '+237600000007', 'permissions' => ['broker.bordereaux.manage', 'broker.bordereaux.submit', 'broker.renewals.manage', 'renewals.manage']],
        // Wave 12 Agent Mode grants: config/permissions.php's 'agent' category is the
        // deliberate exception to 'never_grant_to' — these exist specifically to be
        // granted to AGENT, scoped by AgentPartnerResolver ownership checks rather
        // than by withholding the permission itself.
        ['role_code' => 'AGENT', 'label' => 'Commercial agent', 'name' => 'Demo Commercial Agent', 'email' => 'demo-agent@opesinsure.local', 'phone' => '+237600000008', 'permissions' => ['agent.clients.read', 'agent.clients.manage', 'agent.commissions.read', 'agent.withdrawals.read', 'agent.withdrawals.request', 'agent.sync.read', 'agent.sync.retry', 'agent.sync.dispatch']],
    ];

    public function run(): void
    {
        $this->call(CanonicalEventSchemaSeeder::class);
        $this->call(NotificationTemplateSeeder::class);
        $this->call(VehicleMakeReferenceSeeder::class);
        $this->call(MobileOAuthClientSeeder::class);

        if (! app()->environment(['local', 'testing']) && ! config('demo.enabled')) {
            return;
        }

        $password = env('LOCAL_ADMIN_PASSWORD') ?: (config('demo.enabled') ? config('demo.password') : null);

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
            $user = User::firstOrCreate(
                ['email' => $account['email']],
                ['id' => (string) Str::uuid(), 'full_name' => $account['name'], 'phone_e164' => $account['phone'], 'password' => Hash::make($password), 'locale' => 'en', 'status' => 'ACTIVE', 'email_verified_at' => now(), 'phone_verified_at' => now()],
            );

            $membership = TenantMembership::firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $account['role_code']],
                ['id' => (string) Str::uuid(), 'status' => 'ACTIVE'],
            );

            $role = Role::firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $account['role_code']],
                ['id' => (string) Str::uuid(), 'permissions' => $account['permissions'] ?? ['*'], 'is_system' => true],
            );

            $membership->roles()->syncWithoutDetaching([$role->id]);
        }

        $this->call(DemoMobileAccountSeeder::class);
    }
}
