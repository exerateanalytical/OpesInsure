<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\Regulatory\CimaCoverageRules;
use App\Application\Regulatory\CimaLegacyAuthorizationService;
use App\Application\Regulatory\CimaProductMappingService;
use App\Application\Regulatory\RegulatoryTerminologyService;
use App\Models\Carrier;
use App\Models\InsuranceProduct;
use App\Models\Regulatory\CompulsoryInsuranceRule;
use App\Models\Regulatory\InsurerAuthorizedBranch;
use App\Models\Regulatory\InsurerRegulatoryAuthorization;
use App\Models\Regulatory\LegalReference;
use App\Models\Regulatory\MicroinsuranceBranch;
use App\Models\Regulatory\RegulatoryAuthority;
use App\Models\Regulatory\RegulatoryBranch;
use App\Models\Regulatory\RegulatoryClassDefault;
use App\Models\Regulatory\RegulatoryRegime;
use App\Models\Regulatory\RegulatoryReportingCategory;
use App\Models\Regulatory\RegulatoryTerm;
use App\Models\Regulatory\RegulatoryTermTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CIMA Regulatory Dictionary — loads database/data/cima_regulatory_master_2026.json
 * verbatim (no legal content beyond the file). Idempotent: rows are keyed on
 * their natural key + regulatory_version and only ever inserted, never
 * updated or deleted. Also:
 *  - platform default class → branch mappings (owner instruction: mapping is
 *    automatic per normalized class, overridable by product admins);
 *  - applies those defaults to every product that has no mapping yet;
 *  - DEMO authorizations for is_demo carriers only (real insurers get none:
 *    there is no regulator data on their authorized branches).
 *
 * Entry point: `php artisan opesinsure:seed-cima` (hooked into optimize).
 */
final class CimaRegulatoryDictionarySeeder extends Seeder
{
    public const DATA_FILE = 'data/cima_regulatory_master_2026.json';

    /** Platform effective date for the 2026 dataset. */
    public const EFFECTIVE_FROM = '2026-01-01';

    public const DEFAULTS_SOURCE = 'OpesInsure platform default class mapping (owner instruction 2026-09-24)';

    /**
     * line_code => [[branch number, relationship, requires coverage code|null], ...].
     * Only mappings that are clearly correct; see the report for what is left unmapped.
     */
    public const CLASS_DEFAULTS = [
        'MOTOR' => [[10, 'PRIMARY', null], [3, 'PRIMARY', 'OWN_DAMAGE'], [18, 'ACCESSORY', 'ASSISTANCE']],
        'HEALTH' => [[2, 'PRIMARY', null]],
        'ACCIDENT' => [[1, 'PRIMARY', null]],
        'PERSONAL_ACCIDENT' => [[1, 'PRIMARY', null]],
        'TRAVEL' => [[18, 'PRIMARY', null]],
        'HOME' => [[8, 'PRIMARY', null], [9, 'PRIMARY', null]],
        'PROPERTY' => [[8, 'PRIMARY', null], [9, 'PRIMARY', null]],
        'BUSINESS' => [[13, 'PRIMARY', 'PUBLIC_LIABILITY'], [8, 'PRIMARY', 'PREMISES_FIRE'], [9, 'PRIMARY', 'STOCK_THEFT']],
        'LIFE' => [[20, 'PRIMARY', null]],
    ];

    /** @var array<string, int> */
    public array $counts = [];

    public function run(): void
    {
        $d = self::data();
        $v = $d['regulatory_version'];
        $src = $d['source'];
        $base = ['effective_from' => self::EFFECTIVE_FROM, 'regulatory_version' => $v, 'source_reference' => $src, 'status' => 'ACTIVE', 'is_seeded' => true];

        DB::transaction(function () use ($d, $v, $base) {
            $this->put(RegulatoryRegime::class, ['code' => $d['regime'], 'regulatory_version' => $v], [
                'name_fr' => collect($d['authorities'])->firstWhere('code', $d['regime'])['fr'] ?? $d['regime'], 'name_en' => null, 'jurisdiction' => 'CIMA',
                'metadata' => ['branch_subclasses_note' => $d['branch_subclasses_note'], 'party_roles' => $d['party_roles'], 'example_product_mappings' => $d['example_product_mappings']],
            ] + $base);

            foreach ($d['branches'] as $b) {
                $this->put(RegulatoryBranch::class, ['code' => $b['code'], 'regulatory_version' => $v], [
                    'regime' => $d['regime'], 'number' => $b['number'], 'label_fr' => $b['fr'], 'label_en' => $b['en'], 'business_family' => $b['family'],
                    'reserved' => (bool) ($b['reserved'] ?? false), 'accessory_allowed' => (bool) ($b['accessory_allowed'] ?? true),
                    'complementary_covers_allowed' => (bool) ($b['complementary_covers_allowed'] ?? false),
                    'is_compulsory' => (bool) ($b['compulsory'] ?? false), 'compulsory_basis' => $b['compulsory_basis'] ?? null,
                    'legal_reference' => $b['legal_reference'] ?? null,
                ] + $base);
            }
            // Article 328 subdivisions: structure only (regulatory_branch_subclasses), to be entered from the Code text — see regime metadata note.

            foreach ($d['micro_branches'] as $b) {
                $this->put(MicroinsuranceBranch::class, ['code' => $b['code'], 'regulatory_version' => $v], [
                    'regime' => $d['regime'], 'number' => $b['number'], 'label_fr' => $b['fr'], 'label_en' => $b['en'],
                    'business_family' => $b['family'], 'legal_reference' => $d['micro_legal_reference'],
                ] + $base);
            }

            foreach ($d['reporting_categories']['codes'] as $i => $code) {
                $this->put(RegulatoryReportingCategory::class, ['kind' => 'ART_411_CATEGORY', 'code' => $code, 'regulatory_version' => $v], [
                    'regime' => $d['regime'], 'sequence' => $i + 1, 'label_fr' => null, 'label_en' => null, 'legal_reference' => $d['reporting_categories']['legal_reference'],
                ] + $base);
            }
            foreach ($d['intermediary_reporting']['measures'] as $i => $m) {
                $this->put(RegulatoryReportingCategory::class, ['kind' => 'ART_557_MEASURE', 'code' => $m['code'], 'regulatory_version' => $v], [
                    'regime' => $d['regime'], 'sequence' => $i + 1, 'label_fr' => $m['fr'], 'label_en' => $m['en'], 'legal_reference' => $d['intermediary_reporting']['legal_reference'],
                ] + $base);
            }

            foreach ($d['terms'] as $t) {
                $term = $this->put(RegulatoryTerm::class, ['namespace' => 'CIMA_TERM', 'code' => $t['code'], 'regulatory_version' => $v], [
                    'regime' => $d['regime'], 'category' => $t['category'], 'source_article' => $t['source_article'] ?? null, 'preferred' => true, 'notes' => $t['note'] ?? null,
                ] + $base);
                $this->translation($term, 'fr', $t['fr'], 'PREFERRED');
                $this->translation($term, 'en', $t['en'], 'PREFERRED');
                if (! empty($t['alt_fr'])) {
                    $this->translation($term, 'fr', $t['alt_fr'], 'ALTERNATIVE');
                }
            }
            foreach ($d['party_roles'] as $role) {
                $this->put(RegulatoryTerm::class, ['namespace' => 'CIMA_PARTY_ROLE', 'code' => $role, 'regulatory_version' => $v], [
                    'regime' => $d['regime'], 'category' => 'PARTY_ROLE', 'source_article' => null, 'preferred' => true, 'notes' => null,
                ] + $base);
            }

            foreach ($d['authorities'] as $a) {
                $this->put(RegulatoryAuthority::class, ['code' => $a['code'], 'regulatory_version' => $v], ['regime' => $d['regime'], 'name_fr' => $a['fr'], 'name_en' => $a['en'] ?? null] + $base);
            }
            foreach ($d['legal_references'] as $ref) {
                $this->put(LegalReference::class, ['regime' => $d['regime'], 'reference' => $ref, 'regulatory_version' => $v], ['title' => null, 'summary' => null] + $base);
            }
            foreach ($d['compulsory_insurance'] as $c) {
                $branch = collect($d['branches'])->firstWhere('number', $c['cima_branch']);
                $this->put(CompulsoryInsuranceRule::class, ['code' => $c['code'], 'regulatory_version' => $v], [
                    'regime' => $d['regime'], 'branch_code' => $branch['code'], 'basis' => $c['basis'], 'jurisdiction' => $c['jurisdiction'], 'legal_reference' => null,
                ] + $base);
            }

            $codes = collect($d['branches'])->pluck('code', 'number');
            foreach (self::CLASS_DEFAULTS as $line => $rows) {
                foreach ($rows as [$number, $type, $coverage]) {
                    $this->put(RegulatoryClassDefault::class, ['line_code' => $line, 'branch_code' => $codes[$number], 'relationship_type' => $type, 'regulatory_version' => $v], [
                        'regime' => $d['regime'], 'requires_coverage_code' => $coverage, 'source_reference' => self::DEFAULTS_SOURCE,
                    ] + $base);
                }
            }
            // Owner decisions 2026-09-25 items 1-6: coverage-level rules (Q1).
            $this->counts['coverage_rules_created'] = CimaCoverageRules::seed();
        });

        $mapper = app(CimaProductMappingService::class);
        InsuranceProduct::query()->orderBy('code')->each(function (InsuranceProduct $p) use ($mapper) {
            $this->counts['product_mappings_created'] = ($this->counts['product_mappings_created'] ?? 0) + $mapper->applyClassDefaults($p);
        });

        $this->demoAuthorizations($v);
        $this->counts['legacy_authorizations_recorded'] = app(CimaLegacyAuthorizationService::class)->register();
        app(RegulatoryTerminologyService::class)->flush();
    }

    /** @return array<string, mixed> */
    public static function data(): array
    {
        $raw = file_get_contents(database_path(self::DATA_FILE));
        $d = $raw === false ? null : json_decode($raw, true);
        if (! is_array($d) || ($d['regime'] ?? null) !== 'CIMA') {
            throw new RuntimeException('CIMA regulatory master data file missing or invalid: '.self::DATA_FILE);
        }

        return $d;
    }

    /**
     * DEMO authorizations for demo carriers only, covering the PRIMARY/COMPLEMENTARY
     * branches their products are mapped to, so demo products stay publishable.
     */
    private function demoAuthorizations(string $version): void
    {
        Carrier::where('is_demo', true)->orderBy('id')->each(function (Carrier $c) use ($version) {
            $branches = DB::table('product_regulatory_mappings')->join('insurance_products', 'insurance_products.id', '=', 'product_regulatory_mappings.insurance_product_id')
                ->where('insurance_products.carrier_id', $c->id)->whereIn('product_regulatory_mappings.relationship_type', ['PRIMARY', 'COMPLEMENTARY'])
                ->where('product_regulatory_mappings.status', 'ACTIVE')->distinct()->pluck('product_regulatory_mappings.branch_code');
            if ($branches->isEmpty()) {
                return;
            }
            $auth = InsurerRegulatoryAuthorization::firstOrCreate(['carrier_id' => $c->id, 'source' => 'DEMO', 'is_demo' => true], [
                'jurisdiction' => 'CM', 'regime' => 'CIMA', 'status' => 'ACTIVE', 'effective_from' => self::EFFECTIVE_FROM,
                'authorization_reference' => 'DEMO-CIMA-'.($c->canonical_id ?? $c->cima_code ?? $c->id),
                'source_document' => null, 'notes' => 'DEMO authorization for a synthetic demo carrier — not regulator data ('.$version.').',
                'approved_at' => now(), 'is_seeded' => true,
            ]);
            if ($auth->wasRecentlyCreated) {
                $this->counts['demo_authorizations_created'] = ($this->counts['demo_authorizations_created'] ?? 0) + 1;
            }
            foreach ($branches as $code) {
                InsurerAuthorizedBranch::firstOrCreate(['authorization_id' => $auth->id, 'branch_code' => $code], ['status' => 'ACTIVE', 'effective_from' => self::EFFECTIVE_FROM, 'is_seeded' => true]);
            }
        });
    }

    private function translation(RegulatoryTerm $term, string $locale, string $label, string $context): void
    {
        $row = RegulatoryTermTranslation::firstOrCreate(['regulatory_term_id' => $term->id, 'locale' => $locale, 'label' => $label], ['context' => $context, 'is_seeded' => true]);
        $this->tally('regulatory_term_translations', $row->wasRecentlyCreated);
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $class
     */
    private function put(string $class, array $key, array $attrs)
    {
        $row = $class::firstOrCreate($key, $attrs);
        $this->tally($row->getTable(), $row->wasRecentlyCreated);

        return $row;
    }

    private function tally(string $table, bool $created): void
    {
        if ($created) {
            $this->counts[$table] = ($this->counts[$table] ?? 0) + 1;
        }
    }
}
