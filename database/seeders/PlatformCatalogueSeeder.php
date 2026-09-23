<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Carrier;
use App\Models\CoverageDefinition;
use App\Models\ExclusionDefinition;
use App\Models\InsuranceLine;
use App\Models\InsuranceProduct;
use App\Models\Party;
use App\Models\PartyContact;
use App\Models\TariffVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The rateable catalogue the marketplace needs before a single quote can
 * produce an offer: insurance lines whose risk schemas match the facts the
 * mobile risk screen captures, coverage/exclusion definitions, licensed
 * carriers (ASAC members), their products, and APPROVED tariff versions
 * for QuoteService::rate(). Idempotent on stable codes. Amounts are XAF in
 * minor units (x100), matching how the app renders `total_minor / 100`.
 */
final class PlatformCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $lines = [
            'MOTOR' => ['Motor', 'Automobile', ['registration_number', 'fiscal_power', 'usage_type', 'zone'],
                [['THIRD_PARTY', 'Third-party liability', 'Responsabilité civile', 'AMOUNT', true], ['OWN_DAMAGE', 'Own damage', 'Dommages au véhicule', 'AMOUNT', false], ['THEFT_FIRE', 'Theft and fire', 'Vol et incendie', 'AMOUNT', false], ['ASSISTANCE', 'Roadside assistance', 'Assistance routière', 'NONE', false]],
                [['DRUNK_DRIVING', 'Driving under the influence', 'Conduite en état d\'ivresse'], ['UNLICENSED', 'Unlicensed driver', 'Conducteur sans permis'], ['RACING', 'Racing or speed trials', 'Compétitions et courses']]],
            'TRAVEL' => ['Travel', 'Voyage', ['destination_country', 'departure_date', 'return_date', 'traveller_count'],
                [['MEDICAL', 'Emergency medical expenses', 'Frais médicaux d\'urgence', 'AMOUNT', true], ['REPATRIATION', 'Repatriation', 'Rapatriement', 'AMOUNT', true], ['BAGGAGE', 'Baggage loss', 'Perte de bagages', 'AMOUNT', false], ['CANCELLATION', 'Trip cancellation', 'Annulation de voyage', 'AMOUNT', false]],
                [['PRE_EXISTING', 'Pre-existing conditions', 'Affections préexistantes'], ['EXTREME_SPORTS', 'Extreme sports', 'Sports extrêmes']]],
            'HOME' => ['Home', 'Habitation', ['property_type', 'occupancy', 'city', 'declared_value_minor'],
                [['FIRE', 'Fire and explosion', 'Incendie et explosion', 'AMOUNT', true], ['WATER_DAMAGE', 'Water damage', 'Dégâts des eaux', 'AMOUNT', false], ['THEFT', 'Theft', 'Vol', 'AMOUNT', false], ['LIABILITY', 'Household liability', 'Responsabilité civile vie privée', 'AMOUNT', true]],
                [['WEAR_TEAR', 'Wear and tear', 'Usure normale'], ['UNOCCUPIED', 'Unoccupied over 90 days', 'Inoccupation de plus de 90 jours']]],
            'HEALTH' => ['Health', 'Santé', ['beneficiary_count', 'oldest_age', 'coverage_zone', 'plan_type'],
                [['HOSPITALISATION', 'Hospitalisation', 'Hospitalisation', 'AMOUNT', true], ['OUTPATIENT', 'Outpatient care', 'Soins ambulatoires', 'AMOUNT', false], ['MATERNITY', 'Maternity', 'Maternité', 'AMOUNT', false], ['DENTAL_OPTICAL', 'Dental and optical', 'Dentaire et optique', 'AMOUNT', false]],
                [['COSMETIC', 'Cosmetic procedures', 'Chirurgie esthétique'], ['SELF_INFLICTED', 'Self-inflicted injury', 'Blessures volontaires']]],
            'LIFE' => ['Life', 'Vie', ['insured_age', 'cover_amount_minor', 'term_years', 'purpose'],
                [['DEATH', 'Death benefit', 'Capital décès', 'AMOUNT', true], ['DISABILITY', 'Permanent disability', 'Invalidité permanente', 'AMOUNT', false]],
                [['SUICIDE_FIRST_YEAR', 'Suicide within the first year', 'Suicide la première année']]],
        ];

        $coverageIds = [];
        $exclusionIds = [];
        foreach ($lines as $code => [$en, $fr, $required, $coverages, $exclusions]) {
            $line = InsuranceLine::updateOrCreate(['code' => $code], [
                'name' => ['en' => $en, 'fr' => $fr],
                'description' => ['en' => "$en insurance from licensed Cameroon carriers", 'fr' => "Assurance $fr auprès d'assureurs agréés au Cameroun"],
                'status' => 'ACTIVE',
                'risk_schema' => ['required' => $required],
            ]);
            foreach ($coverages as $i => [$cCode, $cEn, $cFr, $limitType, $mandatory]) {
                $c = CoverageDefinition::updateOrCreate(['insurance_line_id' => $line->id, 'code' => $cCode], ['name' => ['en' => $cEn, 'fr' => $cFr], 'description' => ['en' => $cEn, 'fr' => $cFr], 'limit_type' => $limitType, 'mandatory' => $mandatory, 'status' => 'ACTIVE']);
                $coverageIds[$code][$cCode] = [$c->id, $i];
            }
            foreach ($exclusions as [$xCode, $xEn, $xFr]) {
                $x = ExclusionDefinition::updateOrCreate(['insurance_line_id' => $line->id, 'code' => $xCode], ['name' => ['en' => $xEn, 'fr' => $xFr], 'description' => ['en' => $xEn, 'fr' => $xFr], 'status' => 'ACTIVE']);
                $exclusionIds[$code][] = $x->id;
            }
        }

        // Carriers: ASAC-listed non-life/life insurers. cima_code is a stable
        // seed key, not a real CIMA registration number.
        $carriers = [
            'CHANAS' => ['Chanas Assurances', '+237233421474', 'https://www.chanasassurances.com'],
            'SANLAMALLIANZ' => ['SanlamAllianz Cameroun Assurances', '+237233502000', 'https://cm.sanlamallianz.com'],
            'AXA' => ['AXA Assurances Cameroun', '+237233423171', 'https://www.axa.cm'],
            'ACTIVA' => ['ACTIVA Assurances', '+237233501300', 'https://www.activa-cameroun.com'],
            'NSIA' => ['NSIA Assurances', '+237233433113', 'https://www.groupensia.com'],
            'SAAR' => ['SAAR Assurances', '+237233439200', 'https://www.saar-assurances.com'],
            'SANLAMALLIANZ_VIE' => ['SanlamAllianz Cameroun Assurances Vie', '+237233430940', 'https://cm.sanlamallianz.com'],
            'ACTIVA_VIE' => ['ACTIVA Vie', '+237233501300', 'https://www.activa-cameroun.com'],
        ];
        $carrierIds = [];
        foreach ($carriers as $key => [$name, $phone, $site]) {
            $carrier = Carrier::where('cima_code', "ASAC-$key")->first();
            if (! $carrier) {
                $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => $name, 'status' => 'ACTIVE']);
                // Group brands (e.g. Activa / Activa Vie) publish one switchboard
                // number and party_contacts is unique per (type, value).
                PartyContact::firstOrCreate(['type' => 'PHONE', 'normalized_value' => $phone], ['party_id' => $party->id, 'is_primary' => true]);
                $carrier = Carrier::create(['party_id' => $party->id, 'cima_code' => "ASAC-$key", 'status' => 'ACTIVE', 'capabilities' => ['website' => $site, 'digital_issuance' => true]]);
            }
            $carrierIds[$key] = $carrier->id;
        }

        // [carrier, line, code, name, base premium XAF, factors, eligibility, optional coverages, distinct exclusions]
        $motorFactors = [
            ['code' => 'USAGE_COMMERCIAL', 'fact' => 'usage_type', 'operator' => 'IN', 'value' => ['COMMERCIAL', 'TAXI', 'TRANSPORT'], 'basis_points' => 4500],
            ['code' => 'POWER_9_12', 'fact' => 'fiscal_power', 'operator' => 'BETWEEN', 'value' => [9, 12], 'basis_points' => 2000],
            ['code' => 'POWER_13_PLUS', 'fact' => 'fiscal_power', 'operator' => 'BETWEEN', 'value' => [13, 60], 'basis_points' => 5000],
        ];
        $travelFactors = [
            ['code' => 'GROUP_2_4', 'fact' => 'traveller_count', 'operator' => 'BETWEEN', 'value' => [2, 4], 'basis_points' => 7000],
            ['code' => 'GROUP_5_PLUS', 'fact' => 'traveller_count', 'operator' => 'BETWEEN', 'value' => [5, 20], 'basis_points' => 15000],
        ];
        $homeFactors = [
            ['code' => 'RENTED', 'fact' => 'occupancy', 'operator' => 'EQUALS', 'value' => 'RENTED', 'basis_points' => -1500],
            ['code' => 'APARTMENT', 'fact' => 'property_type', 'operator' => 'EQUALS', 'value' => 'APARTMENT', 'basis_points' => -1000],
        ];
        $healthFactors = [
            ['code' => 'FAMILY_PLAN', 'fact' => 'plan_type', 'operator' => 'EQUALS', 'value' => 'FAMILY', 'basis_points' => 9000],
            ['code' => 'MEMBERS_3_5', 'fact' => 'beneficiary_count', 'operator' => 'BETWEEN', 'value' => [3, 5], 'basis_points' => 6000],
            ['code' => 'MEMBERS_6_PLUS', 'fact' => 'beneficiary_count', 'operator' => 'BETWEEN', 'value' => [6, 20], 'basis_points' => 15000],
            ['code' => 'AGE_50_PLUS', 'fact' => 'oldest_age', 'operator' => 'BETWEEN', 'value' => [50, 90], 'basis_points' => 8000],
        ];
        $lifeFactors = [
            ['code' => 'AGE_41_55', 'fact' => 'insured_age', 'operator' => 'BETWEEN', 'value' => [41, 55], 'basis_points' => 6000],
            ['code' => 'AGE_56_PLUS', 'fact' => 'insured_age', 'operator' => 'BETWEEN', 'value' => [56, 75], 'basis_points' => 16000],
        ];

        $products = [
            ['CHANAS', 'MOTOR', 'CHANAS-AUTO', 'Chanas Assur AUTO', 48500, $motorFactors, ['OWN_DAMAGE', 'ASSISTANCE']],
            ['SANLAMALLIANZ', 'MOTOR', 'SA-AUTO', 'Assurance Automobile', 45900, $motorFactors, ['OWN_DAMAGE', 'THEFT_FIRE', 'ASSISTANCE']],
            ['AXA', 'MOTOR', 'AXA-AUTO', 'AXA Auto Essentiel', 52000, $motorFactors, ['ASSISTANCE']],
            ['ACTIVA', 'MOTOR', 'ACTIVA-AUTO', 'Activa Auto Confort', 47200, $motorFactors, ['OWN_DAMAGE', 'THEFT_FIRE']],
            ['NSIA', 'MOTOR', 'NSIA-AUTO', 'NSIA Auto Liberté', 44800, $motorFactors, ['ASSISTANCE']],
            ['SAAR', 'MOTOR', 'SAAR-AUTO', 'SAAR Auto Tranquillité', 46500, $motorFactors, ['OWN_DAMAGE']],
            ['CHANAS', 'TRAVEL', 'CHANAS-VOYAGE', 'Chanas Assur Voyage', 32000, $travelFactors, ['BAGGAGE', 'CANCELLATION']],
            ['SANLAMALLIANZ', 'TRAVEL', 'SA-VOYAGE', 'Assurance Voyage', 29500, $travelFactors, ['BAGGAGE']],
            ['NSIA', 'TRAVEL', 'NSIA-VOYAGE', 'NSIA Voyage Sérénité', 30800, $travelFactors, ['BAGGAGE', 'CANCELLATION']],
            ['CHANAS', 'HOME', 'CHANAS-MRH', 'Multirisque Habitation', 38000, $homeFactors, ['WATER_DAMAGE', 'THEFT']],
            ['SANLAMALLIANZ', 'HOME', 'SA-MRH', 'Multirisque Habitation', 36500, $homeFactors, ['WATER_DAMAGE', 'THEFT']],
            ['ACTIVA', 'HOME', 'ACTIVA-MRH', 'Activa Habitation', 39800, $homeFactors, ['THEFT']],
            ['CHANAS', 'HEALTH', 'CHANAS-SANTE', 'Chanas Assur Santé', 96000, $healthFactors, ['OUTPATIENT', 'MATERNITY', 'DENTAL_OPTICAL']],
            ['SANLAMALLIANZ', 'HEALTH', 'SA-HOSPICARE', 'HospiCare', 78000, $healthFactors, ['OUTPATIENT']],
            ['AXA', 'HEALTH', 'AXA-ELITE', 'Elite Voyage', 125000, $healthFactors, ['OUTPATIENT', 'MATERNITY', 'DENTAL_OPTICAL']],
            ['SANLAMALLIANZ_VIE', 'LIFE', 'SAV-FAMILLE', 'Protection familiale', 60000, $lifeFactors, ['DISABILITY']],
            ['ACTIVA_VIE', 'LIFE', 'ACTIVA-VIE', 'Activa Prévoyance', 58000, $lifeFactors, ['DISABILITY']],
        ];

        foreach ($products as [$carrierKey, $line, $code, $name, $baseXaf, $factors, $optional]) {
            $product = InsuranceProduct::updateOrCreate(['code' => $code], [
                'carrier_id' => $carrierIds[$carrierKey],
                'line_code' => $line,
                'name' => $name,
                'version' => 1,
                'effective_from' => '2026-01-01',
                'effective_until' => null,
                'status' => 'ACTIVE',
                'coverages' => [],
                'eligibility_rules' => ['conditions' => []],
                'published_at' => '2026-01-01 00:00:00',
                'regulatory_reference' => "ASAC/$carrierKey/$line",
            ]);
            $pivot = [];
            foreach ($coverageIds[$line] as $cCode => [$cid, $order]) {
                $pivot[$cid] = [
                    'default_limit_minor' => $line === 'LIFE' ? 1_000_000_000 : 5_000_000_00,
                    'default_deductible_minor' => in_array($cCode, ['OWN_DAMAGE', 'THEFT_FIRE', 'THEFT', 'WATER_DAMAGE'], true) ? 2_500_000 : 0,
                    'configuration' => json_encode([]),
                    'is_optional' => in_array($cCode, $optional, true),
                    'display_order' => $order,
                ];
            }
            $product->coverageDefinitions()->sync($pivot);
            $product->exclusions()->sync(collect($exclusionIds[$line])->mapWithKeys(fn ($id) => [$id => ['configuration' => json_encode([])]])->all());

            $rules = ['required_facts' => $lines[$line][2], 'base_premium_minor' => $baseXaf * 100, 'factors' => $factors];
            TariffVersion::updateOrCreate(['insurance_product_id' => $product->id, 'version' => 1], [
                'effective_from' => '2026-01-01',
                'effective_until' => null,
                'status' => 'APPROVED',
                'input_schema' => ['required' => $lines[$line][2]],
                'rules' => $rules,
                'rules_hash' => hash('sha256', json_encode($rules)),
                'approved_at' => '2026-01-01 00:00:00',
                'approval_reason' => 'Seeded launch tariff',
                'regulatory_reference' => "TARIFF/$code/1",
            ]);
        }

        // ProposalService::create refuses a proposal for a line with no
        // APPROVED disclosure schema, so every rateable line gets one. A
        // "referral_values" hit sends the proposal to a human underwriter;
        // otherwise it is straight-through to payment.
        $questions = [
            'MOTOR' => [
                ['code' => 'prior_claims', 'label' => ['en' => 'Have you made a motor claim in the last 3 years?', 'fr' => 'Avez-vous déclaré un sinistre auto au cours des 3 dernières années ?'], 'type' => 'boolean', 'required' => true, 'referral_values' => [true], 'referral_code' => 'PRIOR_CLAIMS_HISTORY'],
                ['code' => 'licence_valid', 'label' => ['en' => 'Do all drivers hold a valid driving licence?', 'fr' => 'Tous les conducteurs ont-ils un permis valide ?'], 'type' => 'boolean', 'required' => true, 'referral_values' => [false], 'referral_code' => 'UNLICENSED_DRIVER'],
                ['code' => 'modifications', 'label' => ['en' => 'Has the vehicle been modified from factory specification?', 'fr' => 'Le véhicule a-t-il été modifié ?'], 'type' => 'boolean', 'required' => true, 'referral_values' => [true], 'referral_code' => 'MODIFIED_VEHICLE'],
            ],
            'TRAVEL' => [
                ['code' => 'medical_condition', 'label' => ['en' => 'Does any traveller have a pre-existing medical condition?', 'fr' => 'Un voyageur a-t-il une affection préexistante ?'], 'type' => 'boolean', 'required' => true, 'referral_values' => [true], 'referral_code' => 'PRE_EXISTING_CONDITION'],
                ['code' => 'hazardous_activities', 'label' => ['en' => 'Will the trip include extreme sports or hazardous work?', 'fr' => 'Le voyage inclut-il des sports extrêmes ou un travail dangereux ?'], 'type' => 'boolean', 'required' => true, 'referral_values' => [true], 'referral_code' => 'HAZARDOUS_ACTIVITY'],
            ],
            'HOME' => [
                ['code' => 'prior_losses', 'label' => ['en' => 'Has the property suffered fire, flood or theft in the last 5 years?', 'fr' => 'Le logement a-t-il subi un incendie, une inondation ou un vol ces 5 dernières années ?'], 'type' => 'boolean', 'required' => true, 'referral_values' => [true], 'referral_code' => 'PRIOR_LOSSES'],
                ['code' => 'commercial_use', 'label' => ['en' => 'Is any part of the property used for business?', 'fr' => 'Une partie du logement est-elle à usage professionnel ?'], 'type' => 'boolean', 'required' => true, 'referral_values' => [true], 'referral_code' => 'COMMERCIAL_USE'],
            ],
            'HEALTH' => [
                ['code' => 'chronic_condition', 'label' => ['en' => 'Is any member being treated for a chronic condition?', 'fr' => 'Un membre est-il suivi pour une maladie chronique ?'], 'type' => 'boolean', 'required' => true, 'referral_values' => [true], 'referral_code' => 'CHRONIC_CONDITION'],
                ['code' => 'hospitalised_recently', 'label' => ['en' => 'Has any member been hospitalised in the last 12 months?', 'fr' => 'Un membre a-t-il été hospitalisé ces 12 derniers mois ?'], 'type' => 'boolean', 'required' => true, 'referral_values' => [true], 'referral_code' => 'RECENT_HOSPITALISATION'],
            ],
            'LIFE' => [
                ['code' => 'smoker', 'label' => ['en' => 'Does the insured person smoke?', 'fr' => 'La personne assurée fume-t-elle ?'], 'type' => 'boolean', 'required' => true, 'referral_values' => [], 'referral_code' => 'SMOKER'],
                ['code' => 'serious_illness', 'label' => ['en' => 'Has the insured person been diagnosed with a serious illness?', 'fr' => 'La personne assurée a-t-elle été diagnostiquée d\'une maladie grave ?'], 'type' => 'boolean', 'required' => true, 'referral_values' => [true], 'referral_code' => 'SERIOUS_ILLNESS'],
            ],
        ];
        $systemUser = \App\Models\User::orderBy('created_at')->value('id');
        foreach ($questions as $lineCode => $qs) {
            \App\Models\DisclosureSchemaVersion::updateOrCreate(
                ['insurance_line_id' => InsuranceLine::where('code', $lineCode)->value('id'), 'version' => 1],
                ['status' => 'APPROVED', 'questions' => $qs, 'schema_hash' => hash('sha256', json_encode($qs)), 'effective_from' => '2026-01-01', 'effective_until' => null, 'approved_at' => '2026-01-01 00:00:00', 'created_by' => $systemUser, 'approved_by' => $systemUser],
            );
        }

        foreach (array_keys($lines) as $line) {
            $rules = ['basis_points' => $line === 'LIFE' ? 0 : 1925];
            DB::table('tax_levy_versions')->updateOrInsert(['jurisdiction' => 'CM', 'line_code' => $line, 'version' => 1], [
                'id' => DB::table('tax_levy_versions')->where(['jurisdiction' => 'CM', 'line_code' => $line, 'version' => 1])->value('id') ?? (string) \Illuminate\Support\Str::uuid(),
                'effective_from' => '2026-01-01', 'effective_until' => null, 'status' => 'APPROVED',
                'rules' => json_encode($rules), 'rules_hash' => hash('sha256', json_encode($rules)),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $fee = ['fixed_minor' => 100000];
        DB::table('fee_schedule_versions')->updateOrInsert(['tenant_id' => null, 'code' => 'PLATFORM_FEE', 'version' => 1], [
            'id' => DB::table('fee_schedule_versions')->where(['code' => 'PLATFORM_FEE', 'version' => 1])->whereNull('tenant_id')->value('id') ?? (string) \Illuminate\Support\Str::uuid(),
            'effective_from' => '2026-01-01', 'effective_until' => null, 'status' => 'APPROVED',
            'rules' => json_encode($fee), 'rules_hash' => hash('sha256', json_encode($fee)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
