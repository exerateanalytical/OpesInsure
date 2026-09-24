<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Carrier;
use App\Models\InsuranceProduct;
use App\Models\Partner;
use App\Models\Party;
use App\Models\PartyContact;
use App\Models\Tenant;
use App\Models\TenantCustomer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Deterministic transactional data behind the mobile demo personas
 * (DemoMobileAccountSeeder), so every portal screen has something real to
 * load: the customer's vehicle, policies (active + renewal-due), payments,
 * claims (open + paid) and documents; the agent's origin-locked clients and
 * commissions; the broker's clients, production, receivables, compliance
 * and publications; the insurer's referrals, issuance queue and claims.
 *
 * Idempotent: every row is keyed on a stable demo reference and re-running
 * updates in place. Refuses to run unless demo mode is on, like the
 * account seeder it depends on.
 */
final class DemoScenarioSeeder extends Seeder
{
    private Tenant $tenant;

    private string $now;

    public function run(): void
    {
        if (! config('demo.enabled')) {
            $this->command?->warn('demo.enabled is false; demo scenario was not seeded.');

            return;
        }

        $this->call(PlatformCatalogueSeeder::class);
        $this->call(DemoMobileAccountSeeder::class);

        $this->tenant = Tenant::where('slug', 'opesinsure-platform')->firstOrFail();
        $this->now = now()->toDateTimeString();

        $customer = User::where('phone_e164', '+237600000100')->firstOrFail();
        $agent = User::where('phone_e164', '+237600000101')->firstOrFail();
        $broker = User::where('phone_e164', '+237600000102')->firstOrFail();
        $insurer = User::where('phone_e164', '+237600000103')->firstOrFail();

        $chanas = Carrier::where('cima_code', 'ASAC-CHANAS')->firstOrFail();
        $sanlam = Carrier::where('cima_code', 'ASAC-SANLAMALLIANZ')->firstOrFail();

        $this->customerScenario($customer, $chanas, $sanlam);
        $agentPartner = $this->agentScenario($agent, $chanas);
        $this->brokerScenario($broker, $chanas, $sanlam);
        $this->carrierScenario($insurer, $chanas, $customer);
    }

    // ---------------------------------------------------------------- customer

    private function customerScenario(User $customer, Carrier $chanas, Carrier $sanlam): void
    {
        $party = $customer->party_id;
        $t = $this->tenant->id;

        $vehicleFacts = ['registration_number' => 'LT 452 CD', 'fiscal_power' => 8, 'usage_type' => 'PRIVATE', 'zone' => 'CAMEROON', 'make' => 'Toyota', 'model' => 'Corolla', 'year' => 2019];
        $vehicle = $this->upsert('risk_assets', ['tenant_id' => $t, 'party_id' => $party, 'display_name' => 'Toyota Corolla · LT 452 CD'], [
            'type' => 'VEHICLE', 'facts' => json_encode($vehicleFacts), 'facts_hash' => hash('sha256', json_encode($vehicleFacts)), 'status' => 'ACTIVE', 'version' => 1,
        ]);

        $motorChanas = InsuranceProduct::where('code', 'CHANAS-AUTO')->firstOrFail();
        $homeSanlam = InsuranceProduct::where('code', 'SA-MRH')->firstOrFail();

        // Policy 1: active motor policy issued 2 months ago, paid by MTN MoMo.
        $p1 = $this->purchaseChain('DEMO-CUST-MOTOR', $party, $motorChanas, $vehicle, $vehicleFacts, 5_662_000, now()->subMonths(2), now()->addMonths(10), 'POL-2026-000101', 'ACTIVE');
        // Policy 2: home policy expiring in 18 days -> renewal due.
        $homeFacts = ['property_type' => 'APARTMENT', 'occupancy' => 'OWNER_OCCUPIED', 'city' => 'DOUALA', 'declared_value_minor' => 25_000_000_00];
        $p2 = $this->purchaseChain('DEMO-CUST-HOME', $party, $homeSanlam, null, $homeFacts, 4_353_000, now()->subDays(347), now()->addDays(18), 'POL-2025-004417', 'ACTIVE');
        // Policy 3: an expired travel policy from last year, for history.
        $travel = InsuranceProduct::where('code', 'CHANAS-VOYAGE')->firstOrFail();
        $travelFacts = ['destination_country' => 'FRANCE', 'departure_date' => '2025-07-10', 'return_date' => '2025-07-31', 'traveller_count' => 1];
        $this->purchaseChain('DEMO-CUST-TRAVEL', $party, $travel, null, $travelFacts, 3_816_000, now()->subMonths(14), now()->subMonths(13), 'POL-2025-001982', 'EXPIRED');

        $this->upsert('renewal_cases', ['tenant_id' => $t, 'policy_id' => $p2['policy_id']], [
            'status' => 'DUE', 'due_on' => now()->addDays(18)->toDateString(), 'attribution_snapshot' => json_encode(['origin_type' => 'SYSTEM']), 'contact_attempts' => 0,
        ]);
        $this->upsert('renewal_work_items', ['tenant_id' => $t, 'policy_id' => $p2['policy_id']], [
            'renewal_due_on' => now()->addDays(18)->toDateString(), 'status' => 'DUE', 'contact_attempts' => 0,
        ]);

        // A failed payment attempt on the home renewal quote, so the payments
        // list shows a real failure state.
        $this->upsert('payment_intents', ['tenant_id' => $t, 'idempotency_key' => 'DEMO-CUST-FAILED-PAYMENT'], [
            'proposal_id' => $p2['proposal_id'], 'provider' => 'orange_money', 'provider_reference' => 'OM-DEMO-FAIL-0001', 'payer_phone_e164' => '+237600000100',
            'amount_minor' => 4_353_000, 'currency' => 'XAF', 'status' => 'FAILED', 'provider_snapshot' => json_encode(['reason' => 'INSUFFICIENT_FUNDS', 'demo' => true]),
            'request_channel' => 'MOBILE', 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
        ]);

        // Claim A: open (acknowledged, evidence pending) on the motor policy.
        $claimA = $this->upsert('claims', ['tenant_id' => $t, 'claim_number' => 'CLM-2026-000731'], [
            'policy_id' => $p1['policy_id'], 'claimant_party_id' => $party, 'status' => 'EVIDENCE_PENDING',
            'loss_occurred_at' => now()->subDays(6), 'loss_location' => 'Carrefour Ndokoti, Douala',
            'loss_details' => json_encode(['description' => 'Rear-ended at a traffic light on Boulevard de la République; rear bumper and lights damaged.', 'incident' => ['incident_type' => 'COLLISION', 'police_report_number' => 'PV-DLA-2026-4471', 'injuries_reported' => false, 'vehicle_drivable' => true, 'towing_required' => false, 'declaration_confirmed' => true], 'inspection' => ['status' => 'SCHEDULED', 'appointment_at' => now()->addDays(2)->setTime(10, 0)->toIso8601String(), 'location' => 'Chanas Assurances, Rue Joss, Bonanjo, Douala', 'surveyor_name' => 'M. Tchoupo', 'contact_phone' => '+237233421474', 'notes' => 'Bring the vehicle and the police report.'], 'repair' => ['status' => 'ESTIMATE_PENDING', 'garage_name' => 'Garage Central Bonabéri', 'estimate_minor' => 38_500_000]]),
            'currency' => 'XAF', 'estimated_loss_minor' => 38_500_000, 'priority' => 'NORMAL', 'version' => 3,
            'submitted_at' => now()->subDays(5), 'acknowledged_at' => now()->subDays(4),
        ]);
        $this->claimEvents($claimA, [['SUBMITTED', null, 'SUBMITTED', 5], ['ACKNOWLEDGED', 'SUBMITTED', 'ACKNOWLEDGED', 4], ['EVIDENCE_REQUESTED', 'ACKNOWLEDGED', 'EVIDENCE_PENDING', 3]]);

        // Claim B: approved and paid on the home policy (water damage), 3 months ago.
        $claimB = $this->upsert('claims', ['tenant_id' => $t, 'claim_number' => 'CLM-2026-000412'], [
            'policy_id' => $p2['policy_id'], 'claimant_party_id' => $party, 'status' => 'PAID',
            'loss_occurred_at' => now()->subMonths(3)->subDays(4), 'loss_location' => 'Bonapriso, Douala',
            'loss_details' => json_encode(['description' => 'Burst pipe flooded the kitchen and living room floor.', 'incident' => ['incident_type' => 'WATER_DAMAGE', 'injuries_reported' => false, 'vehicle_drivable' => false, 'towing_required' => false, 'declaration_confirmed' => true], 'settlement' => ['terms' => 'Indemnity for floor and kitchen unit replacement less the policy deductible.', 'decision_deadline' => now()->subMonths(2)->toIso8601String()]]),
            'currency' => 'XAF', 'estimated_loss_minor' => 92_000_000, 'approved_amount_minor' => 86_000_000, 'priority' => 'NORMAL', 'version' => 7,
            'submitted_at' => now()->subMonths(3)->subDays(3), 'acknowledged_at' => now()->subMonths(3)->subDays(2), 'closed_at' => null,
        ]);
        $this->claimEvents($claimB, [['SUBMITTED', null, 'SUBMITTED', 94], ['ACKNOWLEDGED', 'SUBMITTED', 'ACKNOWLEDGED', 93], ['ASSESSED', 'ACKNOWLEDGED', 'ASSESSMENT', 88], ['REFERRED', 'ASSESSMENT', 'CARRIER_REVIEW', 84], ['DECIDED', 'CARRIER_REVIEW', 'APPROVED', 75], ['PAID', 'APPROVED', 'PAID', 70]]);
        $claimsOfficer = User::where('phone_e164', '+237600000006')->value('id') ?? User::where('phone_e164', '+237600000000')->value('id');
        $claimsManager = User::where('phone_e164', '+237600000005')->value('id') ?? $claimsOfficer;
        $decision = $this->upsert('claim_decisions', ['claim_id' => $claimB, 'decision' => 'APPROVED'], [
            'proposed_by' => $claimsOfficer, 'approved_by' => $claimsManager !== $claimsOfficer ? $claimsManager : null,
            'approved_amount_minor' => 86_000_000, 'currency' => 'XAF', 'reason_code' => 'COVERED_PERIL', 'rationale' => 'Sudden water damage is a covered peril; wear-and-tear exclusion does not apply.',
            'authority_snapshot' => json_encode(['limit_minor' => 500_000_000]), 'status' => 'APPROVED', 'approved_at' => now()->subDays(75),
        ]);
        $this->upsert('claim_payments', ['claim_id' => $claimB, 'idempotency_key' => 'DEMO-CLAIM-B-PAYMENT'], [
            'claim_decision_id' => $decision, 'payee_party_id' => $party, 'amount_minor' => 86_000_000, 'currency' => 'XAF', 'status' => 'PAID', 'requested_by' => $claimsOfficer, 'approved_by' => $claimsManager !== $claimsOfficer ? $claimsManager : null,
            'external_reference' => 'MOMO-CLM-88214', 'attempt_count' => 1, 'approved_at' => now()->subDays(72), 'paid_at' => now()->subDays(70),
        ]);

        // Documents the wallet/document screens can open.
        foreach ([['POLICY_DOCUMENT', 'policies/demo/POL-2026-000101.pdf', 184_320], ['CERTIFICATE', 'certificates/demo/CERT-2026-000101.pdf', 96_120], ['CLAIM_EVIDENCE', 'claims/demo/CLM-2026-000731/photo-1.jpg', 1_204_811]] as [$cat, $key, $size]) {
            $this->upsert('documents', ['tenant_id' => $t, 'party_id' => $party, 'storage_key' => $key], [
                'category' => $cat, 'mime_type' => str_ends_with($key, '.pdf') ? 'application/pdf' : 'image/jpeg', 'size_bytes' => $size, 'sha256' => hash('sha256', $key),
                'scan_status' => 'CLEAN', 'verification_status' => 'VERIFIED', 'ocr_data' => json_encode([]),
            ]);
        }
        $evidenceDoc = DB::table('documents')->where('storage_key', 'claims/demo/CLM-2026-000731/photo-1.jpg')->value('id');
        $this->upsert('claim_documents', ['claim_id' => $claimA, 'document_id' => $evidenceDoc], [
            'evidence_type' => 'DAMAGE_PHOTO', 'status' => 'SUBMITTED', 'evidence_hash' => hash('sha256', 'photo-1'), 'submitted_at' => now()->subDays(3),
        ], false);

        // A saved, still-open quote so "Quotes" history is not empty.
        $this->quote('DEMO-CUST-OPEN-QUOTE', $party, 'MOTOR', $vehicleFacts, 'OFFERED', $vehicle, now()->subDays(1));

        // Two devices so the device list is meaningful.
        foreach ([['demo-android-pixel', 'Pixel 8', 'android', 0], ['demo-android-samsung', 'Galaxy A54', 'android', 9]] as [$fp, $name, $platform, $daysAgo]) {
            $this->upsert('user_devices', ['user_id' => $customer->id, 'device_fingerprint' => $fp], ['name' => $name, 'platform' => $platform, 'last_seen_at' => now()->subDays($daysAgo), 'trusted_at' => now()->subDays(30), 'security_metadata' => json_encode(['demo' => true])]);
        }
    }

    // ------------------------------------------------------------------- agent

    private function agentScenario(User $agent, Carrier $chanas): Partner
    {
        $t = $this->tenant->id;
        $partner = Partner::updateOrCreate(['tenant_id' => $t, 'party_id' => $agent->party_id], [
            'type' => 'AGENT', 'licence_number' => 'AG-CM-2024-0187', 'licence_expires_on' => now()->addMonths(14)->toDateString(), 'status' => 'ACTIVE',
            'compliance' => ['agent_code' => 'AG-0187', 'momo_phone_e164' => '+237600000101', 'national_id_masked' => '••••••4471', 'mandate_expires_at' => now()->addMonths(14)->toDateString(), 'items' => [['label' => 'National ID', 'status' => 'VERIFIED'], ['label' => 'Agent mandate', 'status' => 'VERIFIED'], ['label' => 'Training certificate', 'status' => 'PENDING']]],
        ]);
        $this->upsert('partner_licences', ['partner_id' => $partner->id, 'licence_number' => 'AG-CM-2024-0187'], [
            'authority' => 'MINFI', 'licence_type' => 'AGENT_MANDATE', 'issued_on' => now()->subMonths(10)->toDateString(), 'expires_on' => now()->addMonths(14)->toDateString(), 'status' => 'VALID', 'verified_at' => now()->subMonths(10),
        ]);

        $motor = InsuranceProduct::where('code', 'CHANAS-AUTO')->firstOrFail();
        $clients = [
            ['Mireille Kamga', '+237677100201', 'Douala', true, 'LT 118 AB', now()->addDays(25)],
            ['Jean-Paul Nkoulou', '+237677100202', 'Yaoundé', true, 'CE 904 KL', now()->addMonths(7)],
            ['Aïcha Mohamadou', '+237677100203', 'Garoua', false, null, null],
        ];
        $i = 0;
        foreach ($clients as [$name, $phone, $city, $hasPolicy, $plate, $expires]) {
            $i++;
            $party = $this->person($name, $phone, $city);
            TenantCustomer::firstOrCreate(['tenant_id' => $t, 'party_id' => $party], ['customer_number' => "CUST-AG-000$i", 'status' => 'ACTIVE']);
            $this->upsert('customer_attributions', ['party_id' => $party, 'partner_id' => $partner->id], [
                'origin_type' => 'AGENT', 'terms_version' => 'agent-2026-01', 'effective_from' => now()->subMonths(6), 'status' => 'ACTIVE', 'evidence_reference' => "field-visit-$i", 'recorded_by' => $agent->id,
            ]);
            if ($hasPolicy) {
                $facts = ['registration_number' => $plate, 'fiscal_power' => 7, 'usage_type' => 'PRIVATE', 'zone' => 'CAMEROON'];
                $chain = $this->purchaseChain("DEMO-AG-$i", $party, $motor, null, $facts, 5_662_000, now()->subMonths(5), $expires, "POL-2026-00030$i", 'ACTIVE', $agent->id);
                $this->upsert('commission_accruals', ['tenant_id' => $t, 'idempotency_key' => "DEMO-AG-COMM-$i"], [
                    'policy_id' => $chain['policy_id'], 'partner_id' => $partner->id, 'rule_version' => '1', 'amount_minor' => 566_200, 'currency' => 'XAF',
                    'status' => $i === 1 ? 'AVAILABLE' : 'PENDING', 'vested_minor' => $i === 1 ? 566_200 : 0, 'paid_minor' => 0, 'clawed_back_minor' => 0,
                    'vests_at' => now()->subMonths(4), 'available_at' => $i === 1 ? now()->subMonths(4) : now()->addDays(12), 'source_type' => 'POLICY', 'source_id' => $chain['policy_id'],
                ]);
                if ($expires->lessThan(now()->addDays(45))) {
                    $this->upsert('renewal_work_items', ['tenant_id' => $t, 'policy_id' => $chain['policy_id']], ['renewal_due_on' => $expires->toDateString(), 'status' => 'DUE', 'assigned_to' => $agent->id, 'contact_attempts' => 1, 'last_contacted_at' => now()->subDays(2)]);
                }
            }
        }

        $preparer = User::where('phone_e164', '+237600000004')->first() ?? User::where('phone_e164', '+237600000000')->first();
        $this->upsert('partner_statements', ['tenant_id' => $t, 'statement_number' => 'PST-2026-08-0187'], [
            'partner_id' => $partner->id, 'period_start' => now()->subMonth()->startOfMonth()->toDateString(), 'period_end' => now()->subMonth()->endOfMonth()->toDateString(), 'currency' => 'XAF', 'status' => 'PUBLISHED',
            'opening_balance_minor' => 0, 'earned_minor' => 1_132_400, 'clawed_back_minor' => 0, 'paid_minor' => 0, 'closing_balance_minor' => 1_132_400,
            'content_hash' => hash('sha256', 'PST-2026-08-0187'), 'idempotency_key' => 'DEMO-AG-STATEMENT', 'prepared_by' => $preparer?->id, 'published_at' => now()->subDays(20),
        ]);

        return $partner;
    }

    // ------------------------------------------------------------------ broker

    private function brokerScenario(User $broker, Carrier $chanas, Carrier $sanlam): void
    {
        $t = $this->tenant->id;
        $partner = Partner::updateOrCreate(['tenant_id' => $t, 'party_id' => $broker->party_id], [
            'type' => 'BROKER', 'licence_number' => 'BR-MINFI-2022-041', 'licence_expires_on' => now()->addMonths(3)->toDateString(), 'status' => 'ACTIVE',
            'compliance' => ['trading_name' => 'Horizon Courtage', 'offices' => ['Douala', 'Yaoundé']],
        ]);
        $this->upsert('partner_licences', ['partner_id' => $partner->id, 'licence_number' => 'BR-MINFI-2022-041'], [
            'authority' => 'MINFI', 'licence_type' => 'BROKERAGE', 'issued_on' => '2022-03-01', 'expires_on' => now()->addMonths(3)->toDateString(), 'status' => 'VALID', 'verified_at' => now()->subMonths(2),
        ]);

        $motor = InsuranceProduct::where('code', 'SA-AUTO')->firstOrFail();
        $home = InsuranceProduct::where('code', 'CHANAS-MRH')->firstOrFail();
        $clients = [
            ['Société Camerounaise de Transport SARL', '+237677200301', 'Douala', 'ORGANIZATION', [[$motor, 'LT 701 TR', 6_900_000, now()->subMonths(3), now()->addMonths(9)], [$motor, 'LT 702 TR', 6_900_000, now()->subMonths(3), now()->addMonths(9)], [$motor, 'LT 703 TR', 6_900_000, now()->subMonths(3), now()->addDays(20)]]],
            ['Pharmacie du Rond-Point', '+237677200302', 'Yaoundé', 'ORGANIZATION', [[$home, null, 4_540_000, now()->subMonths(8), now()->addMonths(4)]]],
            ['Roger Etoundi', '+237677200303', 'Douala', 'INDIVIDUAL', [[$motor, 'LT 990 RE', 5_477_000, now()->subDays(40), now()->addDays(325)]]],
            ['Clarisse Ngo Bassong', '+237677200304', 'Kribi', 'INDIVIDUAL', []],
        ];
        $i = 0;
        foreach ($clients as [$name, $phone, $city, $type, $policies]) {
            $i++;
            $party = $this->person($name, $phone, $city, $type);
            TenantCustomer::firstOrCreate(['tenant_id' => $t, 'party_id' => $party], ['customer_number' => "CUST-BR-000$i", 'status' => 'ACTIVE']);
            $this->upsert('customer_attributions', ['party_id' => $party, 'partner_id' => $partner->id], [
                'origin_type' => 'BROKER', 'terms_version' => 'broker-2026-01', 'effective_from' => now()->subMonths(9), 'status' => 'ACTIVE', 'evidence_reference' => "mandate-$i", 'recorded_by' => $broker->id,
            ]);
            $j = 0;
            foreach ($policies as [$product, $plate, $premium, $from, $to]) {
                $j++;
                $facts = $plate ? ['registration_number' => $plate, 'fiscal_power' => 11, 'usage_type' => 'COMMERCIAL', 'zone' => 'CAMEROON'] : ['property_type' => 'HOUSE', 'occupancy' => 'RENTED', 'city' => strtoupper($city), 'declared_value_minor' => 40_000_000_00];
                $chain = $this->purchaseChain("DEMO-BR-$i-$j", $party, $product, null, $facts, $premium, $from, $to, sprintf('POL-2026-0009%d%d', $i, $j), 'ACTIVE', $broker->id, $j === 3 ? 'PENDING' : 'SUCCEEDED');
                $this->upsert('commission_accruals', ['tenant_id' => $t, 'idempotency_key' => "DEMO-BR-COMM-$i-$j"], [
                    'policy_id' => $chain['policy_id'], 'partner_id' => $partner->id, 'rule_version' => '1', 'amount_minor' => (int) round($premium * 0.12), 'currency' => 'XAF',
                    'status' => 'PENDING', 'vested_minor' => 0, 'paid_minor' => 0, 'clawed_back_minor' => 0, 'vests_at' => $from->copy()->addDays(30), 'available_at' => $from->copy()->addDays(30), 'source_type' => 'POLICY', 'source_id' => $chain['policy_id'],
                ]);
                if ($to->lessThan(now()->addDays(45))) {
                    $this->upsert('renewal_work_items', ['tenant_id' => $t, 'policy_id' => $chain['policy_id']], ['renewal_due_on' => $to->toDateString(), 'status' => 'DUE', 'assigned_to' => $broker->id, 'contact_attempts' => 0]);
                }
            }
        }

        foreach ([['CC-2026-0142', 'LICENCE_RENEWAL', 'HIGH', now()->addDays(21), 'Brokerage licence expires in 90 days; renewal evidence required.'], ['CC-2026-0139', 'KYC_REFRESH', 'MEDIUM', now()->addDays(40), 'Annual KYC refresh outstanding for 2 corporate clients.']] as [$number, $type, $sev, $due, $finding]) {
            $this->upsert('compliance_cases', ['tenant_id' => $t, 'case_number' => $number], [
                'type' => $type, 'subject_type' => 'partner', 'subject_id' => $partner->id, 'status' => 'OPEN', 'severity' => $sev, 'owner_id' => $broker->id,
                'review_due_on' => $due->toDateString(), 'findings' => json_encode(['summary' => $finding]), 'opened_by' => $broker->id, 'version' => 1,
            ]);
        }
        foreach ([[$motor, 'APPROVED', ['MARKETPLACE', 'AGENT_APP'], now()->subDays(30)], [$home, 'DRAFT', ['MARKETPLACE'], now()->subDays(2)]] as [$product, $status, $channels, $at]) {
            $this->upsert('marketplace_publications', ['tenant_id' => $t, 'product_id' => $product->id], [
                'tariff_version_id' => DB::table('tariff_versions')->where('insurance_product_id', $product->id)->value('id'), 'status' => $status, 'channels' => json_encode($channels),
                'starts_at' => $at, 'ends_at' => null, 'created_by' => $broker->id, 'approved_by' => $status === 'APPROVED' ? (User::where('phone_e164', '+237600000001')->value('id') ?? null) : null, 'approved_at' => $status === 'APPROVED' ? $at : null, 'version' => 1,
            ]);
        }
    }

    // ----------------------------------------------------------------- carrier

    private function carrierScenario(User $insurer, Carrier $chanas, User $customer): void
    {
        $t = $this->tenant->id;
        Partner::updateOrCreate(['tenant_id' => $t, 'party_id' => $insurer->party_id], ['type' => 'CARRIER', 'status' => 'ACTIVE', 'compliance' => ['carrier_id' => $chanas->id, 'carrier_cima_code' => $chanas->cima_code]]);
        // Scope the demo insurer's /mobile/carrier/* reads to Chanas (CarrierScopeResolver).
        \App\Models\TenantMembership::where('tenant_id', $t)->where('user_id', $insurer->id)->whereIn('role_code', ['CARRIER_ADMIN', 'CARRIER_STAFF'])->update(['carrier_id' => $chanas->id]);

        $motor = InsuranceProduct::where('code', 'CHANAS-AUTO')->firstOrFail();
        $referrals = [
            ['DEMO-REF-1', 'Emmanuel Fotso', '+237677300401', ['registration_number' => 'LT 007 EF', 'fiscal_power' => 16, 'usage_type' => 'PRIVATE', 'zone' => 'CAMEROON'], 'HIGH_POWER_VEHICLE', 'QUEUED', 9_355_000],
            ['DEMO-REF-2', 'Transports Nord-Sud SA', '+237677300402', ['registration_number' => 'NW 331 TN', 'fiscal_power' => 14, 'usage_type' => 'TRANSPORT', 'zone' => 'CAMEROON'], 'COMMERCIAL_FLEET', 'IN_REVIEW', 12_106_000],
            ['DEMO-REF-3', 'Brigitte Ateba', '+237677300403', ['registration_number' => 'CE 221 BA', 'fiscal_power' => 9, 'usage_type' => 'TAXI', 'zone' => 'CAMEROON'], 'PRIOR_CLAIMS_HISTORY', 'AWAITING_INFORMATION', 7_920_000],
        ];
        foreach ($referrals as [$key, $name, $phone, $facts, $reason, $status, $premium]) {
            $party = $this->person($name, $phone, 'Douala', str_contains($name, 'SA') ? 'ORGANIZATION' : 'INDIVIDUAL');
            TenantCustomer::firstOrCreate(['tenant_id' => $t, 'party_id' => $party], ['customer_number' => 'CUST-'.$key, 'status' => 'ACTIVE']);
            $q = $this->quote($key, $party, 'MOTOR', $facts, 'REFERRED', null, now()->subDays(3));
            $offer = $this->upsert('quote_offers', ['quote_id' => $q, 'product_id' => $motor->id], [
                'carrier_id' => $chanas->id, 'tariff_version_id' => DB::table('tariff_versions')->where('insurance_product_id', $motor->id)->value('id'),
                'premium_minor' => $premium, 'tax_minor' => (int) round($premium * 0.1925), 'fee_minor' => 100000, 'total_minor' => $premium + (int) round($premium * 0.1925) + 100000, 'currency' => 'XAF', 'status' => 'REFERRED',
                'calculation_breakdown' => json_encode([]), 'coverage_snapshot' => json_encode([]), 'valid_until' => now()->addDays(4), 'comparison_rank' => 1, 'ranking_reasons' => json_encode(['REFERRED_FOR_UNDERWRITING']),
            ]);
            $proposal = $this->upsert('proposals', ['tenant_id' => $t, 'proposal_number' => 'PRP-'.$key], [
                'quote_offer_id' => $offer, 'party_id' => $party, 'status' => 'UNDER_REVIEW', 'disclosures' => json_encode([]), 'submitted_at' => now()->subDays(3), 'version' => 1,
                'terms_snapshot' => json_encode(['premium_minor' => $premium, 'currency' => 'XAF']),
            ]);
            $case = $this->upsert('underwriting_cases', ['tenant_id' => $t, 'proposal_id' => $proposal], [
                'carrier_id' => $chanas->id, 'status' => $status, 'priority' => $reason === 'COMMERCIAL_FLEET' ? 'HIGH' : 'NORMAL', 'referral_reasons' => json_encode([$reason]), 'decision_due_at' => now()->addDays(2),
            ]);
            $this->upsert('underwriting_referral_tasks', ['underwriting_case_id' => $case, 'reason_code' => $reason], [
                'status' => $status === 'QUEUED' ? 'OPEN' : ($status === 'AWAITING_INFORMATION' ? 'WAITING' : 'IN_PROGRESS'), 'severity' => $reason === 'COMMERCIAL_FLEET' ? 'HIGH' : 'MEDIUM', 'assigned_to' => $status === 'QUEUED' ? null : $insurer->id, 'due_at' => now()->addDays(2),
            ]);
        }

        // Issuance queue: two paid proposals waiting for the carrier to issue.
        foreach ([['DEMO-ISS-1', 'Paul Biya Nguema', '+237677300501', 'PENDING'], ['DEMO-ISS-2', 'Solange Mbarga', '+237677300502', 'APPROVED']] as [$key, $name, $phone, $status]) {
            $party = $this->person($name, $phone, 'Yaoundé');
            TenantCustomer::firstOrCreate(['tenant_id' => $t, 'party_id' => $party], ['customer_number' => 'CUST-'.$key, 'status' => 'ACTIVE']);
            $facts = ['registration_number' => 'CE 5'.substr($key, -1).'0 XY', 'fiscal_power' => 8, 'usage_type' => 'PRIVATE', 'zone' => 'CAMEROON'];
            $chain = $this->purchaseChain($key, $party, $motor, null, $facts, 5_662_000, now()->subDays(1), now()->addYear(), null, null, $insurer->id);
            $this->upsert('policy_issuance_requests', ['tenant_id' => $t, 'proposal_id' => $chain['proposal_id']], [
                'payment_intent_id' => $chain['payment_id'], 'carrier_id' => $chanas->id, 'status' => $status, 'authority_snapshot' => json_encode(['delegated' => false]), 'terms_hash' => hash('sha256', $key),
                'coverage_starts_at' => now(), 'coverage_ends_at' => now()->addYear(), 'requested_by' => $customer->id, 'approved_by' => $status === 'APPROVED' ? $insurer->id : null, 'approved_at' => $status === 'APPROVED' ? now()->subHours(2) : null,
            ]);
        }
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{quote_id:string, offer_id:string, proposal_id:string, payment_id:string, policy_id:?string} */
    private function purchaseChain(string $key, string $party, InsuranceProduct $product, ?string $assetId, array $facts, int $premiumMinor, \Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to, ?string $policyNumber, ?string $policyStatus, ?string $requestedBy = null, string $paymentStatus = 'SUCCEEDED'): array
    {
        $t = $this->tenant->id;
        $tariff = DB::table('tariff_versions')->where('insurance_product_id', $product->id)->value('id');
        $tax = (int) round($premiumMinor * ($product->line_code === 'LIFE' ? 0 : 0.1925));
        $total = $premiumMinor + $tax + 100000;
        $quote = $this->quote($key, $party, $product->line_code, $facts, 'ACCEPTED', $assetId, $from->copy()->subDays(2));
        $offer = $this->upsert('quote_offers', ['quote_id' => $quote, 'product_id' => $product->id], [
            'carrier_id' => $product->carrier_id, 'tariff_version_id' => $tariff, 'premium_minor' => $premiumMinor, 'tax_minor' => $tax, 'fee_minor' => 100000, 'total_minor' => $total, 'currency' => 'XAF', 'status' => 'ACCEPTED',
            'calculation_breakdown' => json_encode([['code' => 'BASE', 'amount_minor' => $premiumMinor], ['code' => 'TAX', 'amount_minor' => $tax], ['code' => 'FEE', 'amount_minor' => 100000]]),
            'coverage_snapshot' => json_encode(['coverages' => $product->coverageDefinitions->map(fn ($c) => ['code' => $c->code, 'name' => $c->name, 'mandatory' => $c->mandatory, 'limit_minor' => $c->pivot->default_limit_minor, 'deductible_minor' => $c->pivot->default_deductible_minor, 'optional' => $c->pivot->is_optional])->values()]),
            'valid_until' => $from->copy()->addDays(5), 'comparison_rank' => 1, 'ranking_reasons' => json_encode(['LOWEST_TOTAL_THEN_COVERAGE']),
        ]);
        $proposal = $this->upsert('proposals', ['tenant_id' => $t, 'proposal_number' => 'PRP-'.$key], [
            'quote_offer_id' => $offer, 'party_id' => $party, 'status' => 'APPROVED', 'disclosures' => json_encode(['no_prior_claims' => true, 'accurate_information' => true]), 'submitted_at' => $from->copy()->subDay(), 'decided_at' => $from->copy()->subDay(), 'attested_at' => $from->copy()->subDay(), 'version' => 1,
            'terms_snapshot' => json_encode(['premium_minor' => $premiumMinor, 'tax_minor' => $tax, 'fee_minor' => 100000, 'total_minor' => $total, 'currency' => 'XAF']),
        ]);
        $payment = $this->upsert('payment_intents', ['tenant_id' => $t, 'idempotency_key' => 'DEMO-PAY-'.$key], [
            'proposal_id' => $proposal, 'provider' => 'mtn_momo', 'provider_reference' => 'MOMO-'.strtoupper(substr(md5($key), 0, 10)), 'payer_phone_e164' => DB::table('party_contacts')->where('party_id', $party)->where('type', 'PHONE')->value('normalized_value') ?? '+237600000100',
            'amount_minor' => $total, 'currency' => 'XAF', 'status' => $paymentStatus, 'provider_snapshot' => json_encode(['demo' => true]), 'request_channel' => 'MOBILE', 'requested_by' => $requestedBy,
            'created_at' => $from->copy()->subDay(), 'updated_at' => $from->copy()->subDay(),
        ]);
        $policy = null;
        if ($policyNumber) {
            $policy = $this->upsert('policies', ['tenant_id' => $t, 'policy_number' => $policyNumber], [
                'proposal_id' => $proposal, 'carrier_id' => $product->carrier_id, 'party_id' => $party, 'certificate_number' => 'CERT-'.substr($policyNumber, 4), 'status' => $policyStatus,
                'coverage_starts_at' => $from, 'coverage_ends_at' => $to, 'terms_snapshot' => json_encode(['product' => $product->name, 'line_code' => $product->line_code, 'premium_minor' => $premiumMinor, 'total_minor' => $total, 'currency' => 'XAF', 'risk_facts' => $facts]),
                'version' => 1, 'payment_intent_id' => $payment, 'currency' => 'XAF', 'premium_minor' => $premiumMinor, 'issued_at' => $from, 'issuance_reference' => 'ISS-'.substr($policyNumber, 4), 'terms_hash' => hash('sha256', $policyNumber),
            ]);
        }

        return ['quote_id' => $quote, 'offer_id' => $offer, 'proposal_id' => $proposal, 'payment_id' => $payment, 'policy_id' => $policy];
    }

    private function quote(string $key, string $party, string $line, array $facts, string $status, ?string $assetId, \Carbon\CarbonInterface $at): string
    {
        return $this->upsert('quotes', ['tenant_id' => $this->tenant->id, 'party_id' => $party, 'comparison_context->demo_key' => $key], [
            'comparison_context' => json_encode(['demo_key' => $key, 'algorithm' => 'TOTAL_ASC_THEN_COVERAGE_DESC', 'currency' => 'XAF']),
            'line_code' => $line, 'status' => $status, 'currency' => 'XAF', 'risk_facts' => json_encode($facts), 'risk_asset_id' => $assetId, 'channel' => 'B2C',
            'submitted_at' => $at, 'rated_at' => $at, 'expires_at' => $at->copy()->addDays(7), 'version' => 1,
        ]);
    }

    private function person(string $name, string $phone, string $city, string $type = 'INDIVIDUAL'): string
    {
        $party = Party::firstOrCreate(['display_name' => $name], ['type' => $type, 'status' => 'ACTIVE']);
        PartyContact::firstOrCreate(['type' => 'PHONE', 'normalized_value' => $phone], ['party_id' => $party->id, 'is_primary' => true]);
        DB::table('party_addresses')->updateOrInsert(['party_id' => $party->id, 'type' => 'HOME'], array_filter([
            'id' => DB::table('party_addresses')->where(['party_id' => $party->id, 'type' => 'HOME'])->value('id') ?? (string) Str::uuid(),
            'city' => $city, 'country_code' => 'CM', 'line1' => 'Demo address', 'is_primary' => true, 'created_at' => $this->now, 'updated_at' => $this->now,
        ], fn ($v) => $v !== null));

        return $party->id;
    }

    private function claimEvents(string $claimId, array $events): void
    {
        foreach ($events as [$type, $from, $to, $daysAgo]) {
            $this->upsert('claim_events', ['claim_id' => $claimId, 'type' => $type, 'to_status' => $to], [
                'from_status' => $from, 'reason_code' => $type, 'occurred_at' => now()->subDays($daysAgo), 'details' => json_encode(['demo' => true]),
            ], false);
        }
    }

    /**
     * updateOrInsert keyed on stable demo attributes; returns the row id.
     * Supports one JSON-path key (col->key) for quotes.
     */
    private function upsert(string $table, array $key, array $values, bool $timestamps = true): string
    {
        $q = DB::table($table);
        foreach ($key as $col => $val) {
            if (str_contains($col, '->')) {
                [$c, $path] = explode('->', $col);
                $q->where("$c->$path", $val);
            } else {
                $q->where($col, $val);
            }
        }
        $existing = $q->first();
        $plainKey = array_filter($key, fn ($k) => ! str_contains($k, '->'), ARRAY_FILTER_USE_KEY);
        $stamps = $timestamps ? ['updated_at' => $this->now] : [];
        if ($existing) {
            DB::table($table)->where('id', $existing->id)->update([...$plainKey, ...$values, ...$stamps]);

            return (string) $existing->id;
        }
        $id = (string) Str::uuid();
        DB::table($table)->insert(['id' => $id, ...$plainKey, ...$values, ...($timestamps ? ['created_at' => $this->now, 'updated_at' => $this->now] : [])]);

        return $id;
    }
}
