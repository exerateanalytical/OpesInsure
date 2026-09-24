<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Carrier;
use App\Models\InsuranceClass;
use App\Models\InsurerAuthorization;
use App\Models\IntermediaryAuthorization;
use App\Models\Partner;
use App\Models\Party;
use App\Models\SeedCatalogVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Regulatory layer of the Institutional Seed Data Specification v1
 * (docs/spec/INSTITUTIONAL_SEED_SPEC_V1.md): Cameroon official insurance
 * register 2026 (DGTCFM / MINFI) — 29 licensed insurers, 123 authorized
 * brokers, their 2026 authorizations and the insurance class taxonomy —
 * read verbatim from database/data/cameroon_insurance_register_2026.json.
 *
 * Owner instruction: "Seed this first and never delete it". Idempotent
 * upsert keyed on canonical IDs; it NEVER deletes. Existing carriers are
 * matched (canonical ID -> legal name -> demo key "ASAC-<SHORT>" ->
 * normalized name/aliases) and enriched in place so products and policies
 * stay linked. Carriers it creates reuse the "ASAC-<SHORT>" cima_code so
 * PlatformCatalogueSeeder converges on them whichever runs first.
 * No invented facts: licence numbers, addresses, emails, head-office city
 * and capital stay null.
 *
 * Entry point in production: `php artisan opesinsure:seed-regulatory --country=CM --year=2026`
 * (hooked into `optimize`, so it runs on every deploy regardless of demo mode).
 */
final class CameroonInsuranceRegisterSeeder extends Seeder
{
    public const SOURCE = 'DGTCFM_2026';

    public const AUTHORITY = 'DGTCFM/MINFI';

    public const YEAR = 2026;

    public const DATASET = 'CM_INSURANCE_MARKET';

    public const DATASET_VERSION = '2026.1';

    /** Insurer codes by regulator sequence (owner spec). */
    private const INSURER_CODES = [
        1 => 'ACTIVA', 2 => 'AFG', 3 => 'AFRI', 4 => 'AREA', 5 => 'AGC', 6 => 'AXA', 7 => 'BELIFE_GENERAL', 8 => 'CHANAS', 9 => 'CPA',
        10 => 'GMC', 11 => 'LD', 12 => 'NSIA_IARD', 13 => 'PROASSUR', 14 => 'ROYAL_ONYX', 15 => 'SANLAM_ALLIANZ_IARD', 16 => 'SAAR',
        17 => 'SUNU_IARD', 18 => 'ZENITHE', 19 => 'ACAM_VIE', 20 => 'ACTIVA_VIE', 21 => 'AFRILIFE', 22 => 'BELIFE', 23 => 'CHANAS_VIE',
        24 => 'NSIA_VIE', 25 => 'SAAR_VIE', 26 => 'SANLAM_ALLIANZ_VIE', 27 => 'SONAM_VIE', 28 => 'SUNU_VIE', 29 => 'WAFA_VIE',
    ];

    /** Register heading => [class code, EN, FR]. */
    private const CLASSES = [
        'Motor / Automobile' => ['MOTOR', 'Motor Insurance', 'Assurance Automobile'],
        'Health' => ['HEALTH', 'Health Insurance', 'Assurance Santé'],
        'Personal Accident' => ['PERSONAL_ACCIDENT', 'Personal Accident', 'Accident corporel'],
        'Travel' => ['TRAVEL', 'Travel Insurance', 'Assurance Voyage'],
        'Property' => ['PROPERTY', 'Property Insurance', 'Dommages aux biens'],
        'Business / Professional' => ['BUSINESS_MULTIRISK', 'Business Multirisk', 'Multirisque Professionnelle et Entreprise'],
        'Construction / Engineering' => ['CONSTRUCTION', 'Construction & Engineering', 'Construction et Risques techniques'],
        'Transport' => ['TRANSPORT', 'Transport Insurance', 'Assurance Transport'],
        'Liability' => ['LIABILITY', 'Liability Insurance', 'Responsabilité civile'],
        'Bonds / Caution' => ['SURETY_BONDS', 'Surety Bonds', 'Cautions'],
        'Agriculture' => ['AGRICULTURE', 'Agricultural Insurance', 'Assurance Agricole'],
        'Credit' => ['CREDIT', 'Credit Insurance', 'Assurance Crédit'],
        'Life & Savings' => ['LIFE', 'Life & Savings', 'Vie & Épargne'],
    ];

    /** Register sub-class => [spec class code, EN, FR]. Others get <PARENT>_<SLUG>. */
    private const SPEC_SUB_CLASSES = [
        'Multirisque Habitation' => ['HOME_MULTIRISK', 'Home Multirisk', 'Multirisque Habitation'],
        'Professional Liability' => ['PROFESSIONAL_LIABILITY', 'Professional Liability', 'RC professionnelle'],
        'Marine cargo' => ['MARINE_CARGO', 'Marine Cargo', 'Facultés maritimes'],
        'Inland transit' => ['INLAND_TRANSIT', 'Inland Transit', 'Transport terrestre'],
        'Death/protection' => ['TERM_LIFE', 'Term Life / Death Protection', 'Temporaire décès / prévoyance'],
        'Savings/capitalization' => ['SAVINGS', 'Savings & Capitalization', 'Épargne et capitalisation'],
        'Retirement/pension' => ['RETIREMENT', 'Retirement & Pension', 'Retraite'],
        'Education' => ['EDUCATION', 'Education Plan', 'Assurance Études'],
        'Borrower/credit-linked' => ['CREDIT_LIFE', 'Credit Life / Borrower', 'Assurance Emprunteur'],
        'Group life/employee benefits' => ['GROUP_LIFE', 'Group Life & Employee Benefits', 'Vie collective / avantages salariés'],
        'Funeral' => ['FUNERAL', 'Funeral', 'Obsèques'],
        'Provident/Prévoyance' => ['PROVIDENT', 'Provident', 'Prévoyance'],
        'End-of-career indemnity' => ['END_OF_CAREER', 'End-of-Career Indemnity', 'Indemnités de fin de carrière'],
        'Microinsurance' => ['MICROINSURANCE', 'Microinsurance', 'Micro-assurance'],
        'Bancassurance' => ['BANCASSURANCE', 'Bancassurance', 'Bancassurance'],
    ];

    /** Obvious FR translations of remaining sub-classes; anything else keeps the register wording. */
    private const SUB_FR = [
        'Third-party liability / RC' => 'Responsabilité civile (RC)', 'Own damage' => 'Dommages au véhicule', 'Theft' => 'Vol',
        'Fire' => 'Incendie', 'Fleet' => 'Flotte', 'Motor assistance' => 'Assistance automobile', 'Individual/family health' => 'Santé individuelle / famille',
        'Group health' => 'Santé collective', 'Medical expenses' => 'Frais médicaux', 'Hospitalization' => 'Hospitalisation',
        'Evacuation' => 'Évacuation sanitaire', 'Dental' => 'Dentaire', 'Optical' => 'Optique', 'Maternity' => 'Maternité',
        'Individual Accident' => 'Individuelle accident', 'Group Accident' => 'Accident collectif', 'School Accident' => 'Accident scolaire',
        'Driver/passenger accident' => 'Accident conducteur / passagers', 'Travel medical' => 'Frais médicaux voyage', 'Repatriation' => 'Rapatriement',
        'Medical assistance' => 'Assistance médicale', 'Lost baggage' => 'Perte de bagages', 'Cancellation/interruption' => 'Annulation / interruption',
        'Water damage' => 'Dégâts des eaux', 'Equipment/property damage' => 'Dommages aux équipements et biens', 'Business interruption' => "Perte d'exploitation",
        'Equipment' => 'Équipements', 'Professional liability' => 'RC professionnelle', 'Employer/public liability' => 'RC employeur / exploitation',
        'Machinery breakdown' => 'Bris de machines', 'Electronic/computer risks' => 'Risques informatiques et électroniques',
        'Air transport' => 'Transport aérien', 'Goods in transit' => 'Marchandises transportées', 'Carrier liability' => 'RC transporteur',
        'General/Public Liability' => 'RC générale', 'Family Liability' => 'RC familiale', 'Product Liability' => 'RC produits',
        'Employer Liability' => 'RC employeur', 'School Liability' => 'RC scolaire', 'Bid bond' => 'Caution de soumission',
        'Performance bond' => 'Caution de bonne exécution', 'Advance-payment bond' => "Caution de restitution d'avance",
        'Retention bond' => 'Caution de retenue de garantie', 'Customs/surety' => 'Cautions douanières', 'Crop' => 'Récoltes',
        'Livestock' => 'Bétail', 'Farm assets' => 'Biens agricoles', 'Agricultural multirisk' => 'Multirisque agricole',
        'Trade credit/insolvency' => 'Crédit commercial / insolvabilité',
    ];

    /** Words ignored when matching carrier aliases ("NSIA" vs "NSIA Assurances au Cameroun"). */
    private const ALIAS_NOISE = ['ASSURANCES', 'ASSURANCE', 'INSURANCE', 'CAMEROUN', 'CAMEROON', 'COMPAGNIE', 'CIE', 'AU', 'DU', 'SA', 'SARL', 'IARD'];

    public function run(): void
    {
        $register = self::register();

        DB::transaction(function () use ($register): void {
            $branchCounters = [];
            foreach ($register['insurers'] as $row) {
                $branchCounters[$row['branch']] = ($branchCounters[$row['branch']] ?? 0) + 1;
                $this->upsertInsurer($row, sprintf('CM-INS-%s-%03d', $row['branch'], $branchCounters[$row['branch']]));
            }
            foreach (array_values($register['brokers']) as $i => $name) {
                $this->upsertBroker($i + 1, $name);
            }
            $this->upsertTaxonomy($register['taxonomy']);

            // Carriers outside the register are demo catalogue entries.
            Carrier::where('is_official_register', false)->whereNull('data_origin')->update(['data_origin' => 'DEMO_SYNTHETIC', 'is_demo' => true]);

            SeedCatalogVersion::updateOrCreate(['dataset' => self::DATASET, 'version' => self::DATASET_VERSION], [
                'effective_date' => self::YEAR.'-01-01', 'source' => self::AUTHORITY, 'status' => 'ACTIVE',
                'summary' => ['insurers' => count($register['insurers']), 'brokers' => count($register['brokers']), 'register_source' => self::SOURCE],
            ]);
        });
    }

    /** @return array{insurers: list<array<string, mixed>>, brokers: list<string>, taxonomy: array<string, array<string, list<string>>>} */
    public static function register(): array
    {
        $data = json_decode((string) file_get_contents(database_path('data/cameroon_insurance_register_2026.json')), true, 512, JSON_THROW_ON_ERROR);
        if (count($data['insurers'] ?? []) !== 29 || count($data['brokers'] ?? []) !== 123) {
            throw new RuntimeException('Official register file is incomplete; refusing to seed.');
        }

        return $data;
    }

    public static function normalize(?string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii((string) $value))) ?? '';
    }

    private static function alias(?string $value): string
    {
        $words = preg_split('/[^A-Z0-9]+/', Str::upper(Str::ascii((string) $value)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_diff($words, self::ALIAS_NOISE));
    }

    /** @return array<string, mixed> */
    private static function regulatoryProvenance(): array
    {
        return [
            'data_origin' => 'REGULATORY', 'source_authority' => self::AUTHORITY, 'reference_year' => self::YEAR,
            'regulatory_status' => 'AUTHORIZED', 'register_source' => self::SOURCE, 'country_code' => 'CM',
            'is_official_register' => true, 'is_demo' => false,
        ];
    }

    /** @param array<string, mixed> $row */
    private function upsertInsurer(array $row, string $canonicalId): void
    {
        $code = self::INSURER_CODES[$row['seq']] ?? throw new RuntimeException("No insurer code for register sequence {$row['seq']}");
        $legalName = mb_strtoupper($row['name']);
        $cimaKey = 'ASAC-'.str_replace(' ', '_', Str::upper($row['short']));

        $carrier = Carrier::where('canonical_id', $canonicalId)->first()
            ?? Carrier::where('is_official_register', false)->where('legal_name', $legalName)->first()
            ?? Carrier::where('is_official_register', false)->where('cima_code', $cimaKey)->first()
            ?? $this->matchCarrierByName($row);

        $attributes = self::regulatoryProvenance() + [
            'canonical_id' => $canonicalId, 'insurer_code' => $code, 'slug' => Str::slug($code), 'legal_name' => $legalName,
            'trade_name' => $row['name'], 'short_name' => $row['short'], 'licence_branch' => $row['branch'],
            'regulator_sequence' => $row['seq'], 'currency' => 'XAF', 'product_families' => $row['families'],
            'product_families_origin' => 'CARRIER_PUBLISHED', 'product_families_status' => 'UNVERIFIED',
        ];

        if ($carrier) {
            $carrier->fill($attributes)->save();
        } else {
            $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => $row['name'], 'status' => 'ACTIVE', 'legal_identity' => ['country' => 'CM']]);
            $carrier = Carrier::create($attributes + ['party_id' => $party->id, 'cima_code' => $cimaKey, 'status' => 'ACTIVE', 'capabilities' => []]);
        }

        InsurerAuthorization::updateOrCreate(['carrier_id' => $carrier->id, 'reference_year' => self::YEAR, 'branch' => $row['branch']], [
            'status' => 'AUTHORIZED', 'effective_from' => self::YEAR.'-01-01', 'source_authority' => self::AUTHORITY,
            'data_origin' => 'REGULATORY', 'is_official_register' => true,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function matchCarrierByName(array $row): ?Carrier
    {
        $targets = array_filter([self::normalize($row['name']), self::normalize($row['short']), self::alias($row['name']), self::alias($row['short'])]);

        return Carrier::with('party')->where('is_official_register', false)->orderBy('created_at')->get()->first(function (Carrier $c) use ($targets) {
            $name = $c->party?->display_name;
            $key = Str::after($c->cima_code, '-');

            return array_intersect(array_filter([self::normalize($name), self::normalize($key), self::alias($name), self::alias($key)]), $targets) !== [];
        });
    }

    private function upsertBroker(int $seq, string $name): void
    {
        $canonicalId = sprintf('CM-BRK-%d-%03d', self::YEAR, $seq);
        $legalName = mb_strtoupper($name);

        $partner = Partner::where('canonical_id', $canonicalId)->first()
            ?? Partner::where('type', 'BROKER')->where('is_official_register', false)->where('legal_name', $legalName)->first()
            ?? Partner::with('party')->where('type', 'BROKER')->where('is_official_register', false)->get()
                ->first(fn (Partner $p) => self::normalize($p->party?->display_name) === self::normalize($name));

        $attributes = self::regulatoryProvenance() + [
            'canonical_id' => $canonicalId, 'slug' => Str::slug($name), 'legal_name' => $legalName, 'trade_name' => $name, 'regulator_sequence' => $seq,
        ];

        if ($partner) {
            $partner->fill($attributes)->save();
        } else {
            $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => $name, 'status' => 'ACTIVE', 'legal_identity' => ['country' => 'CM']]);
            $partner = Partner::create($attributes + ['party_id' => $party->id, 'type' => 'BROKER', 'status' => 'ACTIVE', 'compliance' => ['authorized_by' => self::AUTHORITY, 'register' => self::SOURCE]]);
        }

        IntermediaryAuthorization::updateOrCreate(['partner_id' => $partner->id, 'reference_year' => self::YEAR, 'intermediary_type' => 'BROKER'], [
            'regulator_sequence' => $seq, 'status' => 'AUTHORIZED', 'effective_from' => self::YEAR.'-01-01',
            'source_authority' => self::AUTHORITY, 'data_origin' => 'REGULATORY', 'is_official_register' => true,
        ]);
    }

    /** @param array<string, array<string, list<string>>> $taxonomy */
    private function upsertTaxonomy(array $taxonomy): void
    {
        $provenance = ['data_origin' => 'PLATFORM_NORMALIZED', 'source_authority' => self::AUTHORITY, 'reference_year' => self::YEAR, 'register_source' => self::SOURCE, 'is_official_register' => true];
        $order = 0;
        foreach ($taxonomy as $branch => $classes) {
            foreach ($classes as $heading => $subs) {
                [$code, $en, $fr] = self::CLASSES[$heading] ?? [Str::upper(Str::slug($heading, '_')), $heading, $heading];
                $parent = InsuranceClass::updateOrCreate(['code' => $code], $provenance + ['parent_id' => null, 'branch' => $branch, 'name' => ['en' => $en, 'fr' => $fr], 'sort_order' => ++$order]);
                foreach ($subs as $i => $sub) {
                    [$subCode, $subEn, $subFr] = self::SPEC_SUB_CLASSES[$sub]
                        ?? [Str::limit($code.'_'.Str::upper(Str::slug(str_replace('/', ' ', $sub), '_')), 64, ''), $sub, self::SUB_FR[$sub] ?? $sub];
                    InsuranceClass::updateOrCreate(['code' => $subCode], $provenance + ['parent_id' => $parent->id, 'branch' => $branch, 'name' => ['en' => $subEn, 'fr' => $subFr], 'sort_order' => $i + 1]);
                }
            }
        }
    }
}
