<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\Demo\DemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-SEED-005 / REQ-SEED-001 — demo institutional layer (INSTITUTIONAL_SEED_SPEC_V1 rule 8):
 * the fictitious "OPESINSURE DEMO BROKERAGE" (DEMO_ONLY) tenant + partner, 5 demo branches, and demo
 * carrier_broker_agreements linking it to real insurers of the official register. Every row is is_demo=true /
 * data_origin=DEMO_SYNTHETIC; identities use the .invalid domain. Nothing here touches official records beyond
 * reading them. Idempotent (keyed on slug / canonical id / branch code / agreement number).
 * Only runs through demo:seed (DemoScenarioSeeder), which refuses production unless explicitly allowed.
 */
final class DemoInstitutionalSeeder extends Seeder
{
    public const TENANT_SLUG = 'opesinsure-demo-brokerage';

    public const CANONICAL_ID = 'CM-BRK-DEMO-001';

    public const LEGAL_NAME = 'OPESINSURE DEMO BROKERAGE';

    /** code => [name, city] */
    public const BRANCHES = [
        'DEMO-DLA' => ['Demo Branch Douala', 'Douala'],
        'DEMO-YDE' => ['Demo Branch Yaoundé', 'Yaoundé'],
        'DEMO-BAF' => ['Demo Branch Bafoussam', 'Bafoussam'],
        'DEMO-GAR' => ['Demo Branch Garoua', 'Garoua'],
        'DEMO-BDA' => ['Demo Branch Bamenda', 'Bamenda'],
    ];

    private const DEMO = ['is_demo' => true, 'data_origin' => 'DEMO_SYNTHETIC'];

    public function run(): void
    {
        if (! app(DemoEnvironment::class)->seedingAllowed()) {
            $this->command?->warn('Demo seeding is not allowed here; demo institutional layer skipped.');

            return;
        }

        $now = now();
        $tenantId = $this->upsert('tenants', ['slug' => self::TENANT_SLUG], [
            'type' => 'BROKER', 'legal_name' => self::LEGAL_NAME, 'trade_name' => 'OpesInsure Demo', 'status' => 'ACTIVE', 'country_code' => 'CM',
            'currency' => 'XAF', 'settings' => json_encode(['demo_only' => true, 'banner' => DemoEnvironment::BANNER_TEXT]), ...self::DEMO,
        ]);

        $partyId = DB::table('partners')->where('canonical_id', self::CANONICAL_ID)->value('party_id')
            ?? $this->upsert('parties', ['display_name' => self::LEGAL_NAME, 'type' => 'ORGANIZATION'], ['status' => 'ACTIVE', 'legal_identity' => json_encode(['demo_only' => true]), ...self::DEMO]);
        DB::table('party_contacts')->updateOrInsert(['type' => 'EMAIL', 'normalized_value' => 'brokerage@'.DemoEnvironment::IDENTITY_DOMAIN],
            ['id' => DB::table('party_contacts')->where(['type' => 'EMAIL', 'normalized_value' => 'brokerage@'.DemoEnvironment::IDENTITY_DOMAIN])->value('id') ?? (string) Str::uuid(),
                'party_id' => $partyId, 'is_primary' => true, 'created_at' => $now, 'updated_at' => $now]);

        // No licence number, expiry or capital: the brokerage is fictitious (rule 3 — never invent institutional facts).
        $partnerId = $this->upsert('partners', ['canonical_id' => self::CANONICAL_ID], [
            'tenant_id' => $tenantId, 'party_id' => $partyId, 'type' => 'BROKER', 'status' => 'ACTIVE', 'legal_name' => self::LEGAL_NAME,
            'trade_name' => 'OpesInsure Demo', 'slug' => self::TENANT_SLUG, 'country_code' => 'CM', 'compliance' => json_encode(['demo_only' => true]),
            'is_official_register' => false, ...self::DEMO,
        ]);

        foreach (self::BRANCHES as $code => [$name, $city]) {
            $this->upsert('tenant_branches', ['tenant_id' => $tenantId, 'code' => $code], [
                'name' => $name, 'status' => 'ACTIVE', 'email' => strtolower($code).'@'.DemoEnvironment::IDENTITY_DOMAIN,
                'address' => json_encode(['city' => $city, 'country_code' => 'CM', 'line1' => 'Demo address']), 'timezone' => 'Africa/Douala', ...self::DEMO,
            ]);
        }

        // Demo agreements with real insurers only through these flagged rows (rule 8).
        $carriers = DB::table('carriers')->where('is_official_register', true)->where('licence_branch', 'IARD')
            ->orderBy('regulator_sequence')->limit(3)->get(['id', 'insurer_code', 'canonical_id']);
        foreach ($carriers as $carrier) {
            $agreementId = $this->upsert('carrier_broker_agreements', ['agreement_number' => 'DEMO-CBA-'.($carrier->insurer_code ?? $carrier->canonical_id)], [
                'carrier_id' => $carrier->id, 'partner_id' => $partnerId, 'effective_from' => $now->copy()->startOfYear()->toDateString(),
                'effective_until' => $now->copy()->endOfYear()->toDateString(), 'status' => 'ACTIVE', 'territories' => json_encode(['CM']),
                'channels' => json_encode(['B2C', 'AGENT']), ...self::DEMO,
            ]);
            foreach (['MOTOR' => [true, true, true, false], 'PROPERTY' => [true, false, false, true]] as $line => [$quote, $bind, $collect, $approval]) {
                $existing = DB::table('carrier_broker_agreement_products')->where(['agreement_id' => $agreementId, 'line_code' => $line])->whereNull('insurance_product_id')->value('id');
                $values = ['can_quote' => $quote, 'can_bind' => $bind, 'can_collect_premium' => $collect, 'requires_carrier_approval' => $approval,
                    'commission_basis_points' => null, 'status' => 'ACTIVE', 'updated_at' => $now]; // no invented commission
                $existing
                    ? DB::table('carrier_broker_agreement_products')->where('id', $existing)->update($values)
                    : DB::table('carrier_broker_agreement_products')->insert([...$values, 'id' => (string) Str::uuid(), 'agreement_id' => $agreementId, 'line_code' => $line, 'created_at' => $now]);
            }
        }
    }

    private function upsert(string $table, array $key, array $values): string
    {
        $id = DB::table($table)->where($key)->value('id');
        if ($id !== null) {
            DB::table($table)->where('id', $id)->update([...$values, 'updated_at' => now()]);

            return (string) $id;
        }
        $id = (string) Str::uuid();
        DB::table($table)->insert([...$key, ...$values, 'id' => $id, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
