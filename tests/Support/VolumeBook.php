<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Identity\RoleCatalogue;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * R4 performance: a realistic multi-carrier / multi-broker book seeded with set-based SQL (generate_series), so a
 * volume of 20k policies / 5k claims / 50k payments takes seconds. Used by the volume profile
 * (tests/Feature/Performance/VolumeProfileTest.php, opt-in) and, at a small scale, by the query-ceiling regression
 * test (tests/Feature/Performance/DashboardQueryCeilingTest.php).
 */
final class VolumeBook
{
    /**
     * @return array{tenant: Tenant, carriers: list<string>, brokers: list<array{partner:string, party:string}>, users: array<string, User>, customer_party: string}
     */
    public static function seed(int $policies = 20000, int $claims = 5000, int $payments = 50000, int $carriers = 30, int $brokers = 150, int $customers = 5000): array
    {
        $tenant = Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'R4 Volume '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
        $t = $tenant->id;

        DB::statement('DROP TABLE IF EXISTS r4_carrier, r4_product, r4_broker, r4_cust, r4_pol');
        // Reference rows (small): carriers with 3 products each and an approved tariff, broker partners, one agent.
        DB::statement('CREATE TEMP TABLE r4_carrier AS SELECT g AS k, gen_random_uuid() AS id, gen_random_uuid() AS party FROM generate_series(0, ?-1) g', [$carriers]);
        DB::statement("INSERT INTO parties (id, type, display_name, status, created_at, updated_at) SELECT party, 'ORGANIZATION', 'R4 Carrier '||k, 'ACTIVE', now(), now() FROM r4_carrier");
        DB::statement("INSERT INTO carriers (id, party_id, cima_code, status, created_at, updated_at) SELECT id, party, 'R4C-'||k||'-'||substr(md5(random()::text),1,6), 'ACTIVE', now(), now() FROM r4_carrier");
        DB::statement('CREATE TEMP TABLE r4_product AS SELECT c.k * 3 + j AS k, c.id AS carrier, gen_random_uuid() AS id, gen_random_uuid() AS tariff, (ARRAY[\'AUTO\',\'HEALTH\',\'TRAVEL\'])[j+1] AS line FROM r4_carrier c CROSS JOIN generate_series(0,2) j');
        DB::statement("INSERT INTO insurance_products (id, carrier_id, line_code, code, name, version, effective_from, status, created_at, updated_at) SELECT id, carrier, line, 'R4P-'||k, 'R4 Product '||k, 1, current_date - 400, 'ACTIVE', now(), now() FROM r4_product");
        DB::statement("INSERT INTO tariff_versions (id, insurance_product_id, version, effective_from, status, input_schema, rules, rules_hash, created_at, updated_at) SELECT tariff, id, 1, current_date - 400, 'APPROVED', '{}', '{}', md5(id::text)||md5(tariff::text), now(), now() FROM r4_product");
        DB::statement('CREATE TEMP TABLE r4_broker AS SELECT g AS k, gen_random_uuid() AS id, gen_random_uuid() AS party FROM generate_series(0, ?-1) g', [$brokers]);
        DB::statement("INSERT INTO parties (id, type, display_name, status, created_at, updated_at) SELECT party, 'ORGANIZATION', 'R4 Broker '||k, 'ACTIVE', now(), now() FROM r4_broker");
        DB::statement("INSERT INTO partners (id, tenant_id, party_id, type, status, created_at, updated_at) SELECT id, ?, party, 'BROKER', 'ACTIVE', now(), now() FROM r4_broker", [$t]);
        $agentParty = (string) Str::uuid();
        $agent = (string) Str::uuid();
        DB::table('parties')->insert(['id' => $agentParty, 'type' => 'INDIVIDUAL', 'display_name' => 'R4 Agent', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('partners')->insert(['id' => $agent, 'tenant_id' => $t, 'party_id' => $agentParty, 'type' => 'AGENT', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $recorder = User::factory()->create(['status' => 'ACTIVE']);

        // Customers, their tenant customer record, KYC case and attribution: every 50th customer to the agent, the rest spread over the brokers.
        DB::statement('CREATE TEMP TABLE r4_cust AS SELECT g AS k, gen_random_uuid() AS id FROM generate_series(0, ?-1) g', [$customers]);
        DB::statement("INSERT INTO parties (id, type, display_name, status, created_at, updated_at) SELECT id, 'INDIVIDUAL', 'R4 Customer '||k, 'ACTIVE', now() - (k % 400) * interval '1 day', now() FROM r4_cust");
        DB::statement("INSERT INTO tenant_customers (id, tenant_id, party_id, customer_number, status, created_at, updated_at) SELECT gen_random_uuid(), ?, id, 'R4CUS-'||k, 'ACTIVE', now() - (k % 400) * interval '1 day', now() FROM r4_cust", [$t]);
        DB::statement("INSERT INTO kyc_submissions (id, tenant_id, party_id, status, submitted_at, expires_at, created_at, updated_at) SELECT gen_random_uuid(), ?, id, (ARRAY['DRAFT','SUBMITTED','REVIEWING','APPROVED','APPROVED','APPROVED','REJECTED','MORE_INFO_REQUIRED'])[k % 8 + 1], now() - (k % 300) * interval '1 day', now() + (k % 400) * interval '1 day', now(), now() FROM r4_cust", [$t]);
        DB::statement("INSERT INTO customer_attributions (id, party_id, partner_id, origin_type, terms_version, effective_from, status, recorded_by, created_at, updated_at)
            SELECT gen_random_uuid(), c.id, CASE WHEN c.k % 50 = 0 THEN ?::uuid ELSE b.id END, 'BROKER', '1', now() - interval '1 year', 'ACTIVE', ?, now(), now() FROM r4_cust c JOIN r4_broker b ON b.k = c.k % ?", [$agent, $recorder->id, $brokers]);

        // Quote -> offer -> proposal -> policy chain, one per policy; issued over the last ~13 months.
        DB::statement('CREATE TEMP TABLE r4_pol AS SELECT g AS k, gen_random_uuid() AS quote, gen_random_uuid() AS offer, gen_random_uuid() AS proposal, gen_random_uuid() AS id, (g % ?) AS cust, (g % ?) * 3 + (g % 3) AS prod, now() - ((g * 7919) % 400) * interval \'1 day\' - (g % 24) * interval \'1 hour\' AS at FROM generate_series(0, ?-1) g', [$customers, $carriers, $policies]);
        DB::statement("INSERT INTO quotes (id, tenant_id, party_id, line_code, status, currency, risk_facts, expires_at, created_at, updated_at) SELECT p.quote, ?, c.id, pr.line, 'RATED', 'XAF', '{}', p.at + interval '30 days', p.at - interval '2 days', now() FROM r4_pol p JOIN r4_cust c ON c.k = p.cust JOIN r4_product pr ON pr.k = p.prod", [$t]);
        DB::statement("INSERT INTO quote_offers (id, quote_id, carrier_id, product_id, tariff_version_id, premium_minor, total_minor, currency, status, calculation_breakdown, valid_until, created_at, updated_at) SELECT p.offer, p.quote, pr.carrier, pr.id, pr.tariff, 5000000 + (p.k % 97) * 10000, 5000000 + (p.k % 97) * 10000, 'XAF', 'OFFERED', '{}', p.at + interval '7 days', p.at - interval '2 days', now() FROM r4_pol p JOIN r4_product pr ON pr.k = p.prod");
        DB::statement("INSERT INTO proposals (id, tenant_id, quote_offer_id, party_id, status, created_at, updated_at) SELECT p.proposal, ?, p.offer, c.id, 'APPROVED', p.at - interval '1 day', now() FROM r4_pol p JOIN r4_cust c ON c.k = p.cust", [$t]);
        DB::statement("INSERT INTO policies (id, tenant_id, proposal_id, carrier_id, party_id, policy_number, status, coverage_starts_at, coverage_ends_at, terms_snapshot, issued_at, premium_minor, currency, created_at, updated_at)
            SELECT p.id, ?, p.proposal, pr.carrier, c.id, 'R4POL-'||p.k, (ARRAY['ACTIVE','ACTIVE','ACTIVE','ACTIVE','ACTIVE','ACTIVE','ACTIVE','EXPIRED','CANCELLED','PENDING_ISSUANCE'])[p.k % 10 + 1],
                   p.at, p.at + interval '1 year', '{}', CASE WHEN p.k % 10 = 9 THEN NULL ELSE p.at END, 5000000 + (p.k % 97) * 10000, 'XAF', p.at, now()
            FROM r4_pol p JOIN r4_cust c ON c.k = p.cust JOIN r4_product pr ON pr.k = p.prod", [$t]);
        DB::statement("INSERT INTO underwriting_cases (id, tenant_id, proposal_id, carrier_id, status, priority, decision_due_at, created_at, updated_at)
            SELECT gen_random_uuid(), ?, p.proposal, pr.carrier, (ARRAY['QUEUED','IN_REVIEW','AWAITING_INFORMATION','DECISION_PENDING','DECIDED'])[p.k % 5 + 1], 'NORMAL', p.at + interval '3 days', p.at, now()
            FROM r4_pol p JOIN r4_product pr ON pr.k = p.prod WHERE p.k % 10 = 0", [$t]);

        // Claims on the policies, reported over the last year; a third closed.
        DB::statement("INSERT INTO claims (id, tenant_id, policy_id, claimant_party_id, claim_number, status, loss_occurred_at, loss_details, loss_location, currency, current_reserve_minor, approved_amount_minor, estimated_loss_minor, submitted_at, closed_at, created_at, updated_at)
            SELECT gen_random_uuid(), ?, p.id, c.id, 'R4CLM-'||g, (ARRAY['SUBMITTED','REGISTERED','UNDER_ASSESSMENT','APPROVED','CLOSED','SETTLED'])[g % 6 + 1],
                   now() - (g % 360) * interval '1 day' - interval '2 days', '{\"description\":\"x\"}', 'Douala', 'XAF', 200000 + (g % 50) * 1000, CASE WHEN g % 3 = 0 THEN 150000 END, 300000,
                   now() - (g % 360) * interval '1 day', CASE WHEN g % 6 >= 4 THEN now() - (g % 360) * interval '1 day' + interval '12 days' END, now() - (g % 360) * interval '1 day', now()
            FROM generate_series(0, ?-1) g JOIN r4_pol p ON p.k = (g * 4) % ? JOIN r4_cust c ON c.k = p.cust", [$t, $claims, $policies]);

        // Premium payments (intents) on the proposals.
        DB::statement("INSERT INTO payment_intents (id, tenant_id, proposal_id, provider, payer_phone_e164, amount_minor, currency, status, idempotency_key, created_at, updated_at)
            SELECT gen_random_uuid(), ?, p.proposal, 'MTN_MOMO', '+23767'||lpad((g % 10000000)::text, 7, '0'), 5000000, 'XAF', (ARRAY['SUCCEEDED','SUCCEEDED','SUCCEEDED','PENDING','FAILED'])[g % 5 + 1], 'r4-'||g||'-'||md5(random()::text),
                   now() - (g % 400) * interval '1 day', now()
            FROM generate_series(0, ?-1) g JOIN r4_pol p ON p.k = g % ?", [$t, $payments, $policies]);
        DB::statement('ANALYZE parties, carriers, partners, insurance_products, tenant_customers, kyc_submissions, customer_attributions, quotes, quote_offers, proposals, policies, underwriting_cases, claims, payment_intents');

        $carrierIds = DB::table('r4_carrier')->orderBy('k')->pluck('id')->all();
        $brokerRows = DB::table('r4_broker')->orderBy('k')->get()->map(fn ($r) => ['partner' => $r->id, 'party' => $r->party])->all();
        $customerParty = (string) DB::table('r4_cust')->where('k', 1)->value('id');

        $users = [
            'insurer' => self::user($t, 'CARRIER_ADMIN', ['carrier_id' => $carrierIds[0]], ['carrier.dashboard.read', 'carrier.claims.read', 'policies.read', 'claims.view', 'quotes.read']),
            'underwriter' => self::user($t, 'UNDERWRITER', ['carrier_id' => $carrierIds[0]]),
            'broker' => self::user($t, 'BROKER_ADMIN', [], [], $brokerRows[1]['party']),
            'agent' => self::user($t, 'AGENT', [], [], $agentParty),
            'admin' => self::user($t, 'PLATFORM_ADMIN'),
            'customer' => self::user($t, 'CUSTOMER', [], [], $customerParty),
        ];

        return ['tenant' => $tenant, 'carriers' => $carrierIds, 'brokers' => $brokerRows, 'agent' => $agent, 'users' => $users, 'customer_party' => $customerParty];
    }

    /** @param array<string, mixed> $membership @param list<string> $extra */
    public static function user(string $tenantId, string $role, array $membership = [], array $extra = [], ?string $partyId = null): User
    {
        $u = User::factory()->create(['status' => 'ACTIVE', 'party_id' => $partyId, 'locale' => 'en']);
        $m = TenantMembership::forceCreate(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE'] + $membership);
        $r = Role::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'code' => $role.'_R4'.Str::random(4),
            'permissions' => array_values(array_unique([...RoleCatalogue::defaultPermissions($role), ...$extra])), 'is_system' => false]);
        $m->roles()->syncWithoutDetaching([$r->id]);

        return $u;
    }
}
