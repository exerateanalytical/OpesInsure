<?php

declare(strict_types=1);

namespace App\Application\DataReadiness;

use App\Application\Authority\AuthorityTypeCatalogue;
use App\Application\Capabilities\CapabilityCatalogue;
use App\Application\Cases\CaseTypeCatalogue;
use App\Application\DocumentCatalogue\ProductDocumentRequirementService;
use App\Application\Documents\Engine\DocumentRegister;
use App\Application\Kyc\KycRequirementService;
use App\Application\Kyc\Risk\CustomerRiskRatingService;
use App\Application\Kyc\Screening\ScreeningMode;
use App\Application\Notifications\NotificationVocabulary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Data Readiness registry: every domain of the Workflow Institutional Data Master v1 with its status (DataStatus
 * vocabulary), owner, source and what is missing. Declared statuses come from the owner's file; wherever the platform
 * holds the data, the status is COMPUTED from the canonical tables/lists (an owner "CONFIG_REQUIRED" becomes VERIFIED
 * once it is actually configured, and a claimed value that is absent is reported as missing).
 *
 * Other domain owners plug their computed checks with DataReadinessRegistry::extend($domain, fn (array $section): array)
 * returning rows (see row()); their rows replace the generic declared rows with the same item.
 */
final class DataReadinessRegistry
{
    public const OWNERS = [
        'regulatory' => 'Compliance', 'organization_capabilities' => 'Platform operations', 'broker_insurer_agreements' => 'Distribution',
        'authority' => 'Platform administration', 'kyc_aml' => 'Compliance', 'sla_calendars' => 'Operations', 'geography' => 'Master data',
        'party_roles' => 'Master data', 'beneficiary_relationships' => 'Master data', 'legal_entity_types' => 'Master data',
        'occupations_industries' => 'Master data', 'vehicles' => 'Master data', 'property_engineering' => 'Master data', 'health' => 'Claims (health)',
        'claims' => 'Claims', 'repair_network' => 'Claims', 'marine_cargo' => 'Master data', 'agriculture_livestock' => 'Master data',
        'aviation' => 'Master data', 'payments_finance' => 'Finance', 'accounting' => 'Finance', 'commission' => 'Finance',
        'reinsurance_coinsurance' => 'Reinsurance', 'documents' => 'Document engine', 'case_management' => 'Operations',
        'notifications' => 'Customer communications', 'complaints' => 'Operations', 'fraud_controls' => 'Compliance',
        'regulatory_reporting' => 'Compliance', 'ict_controls' => 'IT security', 'document_requirement_rules' => 'Document engine',
    ];

    /** @var array<string, list<callable>> */
    private static array $extensions = [];

    public function __construct(private readonly WorkflowDataMaster $master) {}

    /** @param callable(array<string, mixed>): list<array<string, mixed>> $check */
    public static function extend(string $domain, callable $check): void
    {
        self::$extensions[$domain][] = $check;
    }

    /** @return list<array<string, mixed>> one row per (domain, item) */
    public function items(): array
    {
        $out = [];
        foreach ($this->master->domains() as $domain) {
            array_push($out, ...$this->domain($domain));
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function domain(string $domain): array
    {
        $section = $this->master->section($domain);
        $rows = [];
        foreach ($this->generic($domain, $section) as $r) {
            $rows[$r['item']] = $r;
        }
        // Master-data owner items (wm2): the canonical per-item status store is master_data_workflow_statuses — reused, not duplicated.
        if ($this->has('master_data_workflow_statuses')) {
            foreach (DB::table('master_data_workflow_statuses')->where('owner_domain', $domain)->get() as $w) {
                $rows[$w->owner_item] = $this->row($domain, $w->owner_item, $w->owner_status, $w->status, $this->missingFor($w->status),
                    trim(($w->note ?? '').' ['.$w->target.($w->list_code ? ':'.$w->list_code : '').']'), 'master_data_workflow_statuses');
            }
        }
        $checks = [...(method_exists($this, $m = 'check'.str_replace('_', '', ucwords($domain, '_'))) ? [fn (array $s) => $this->{$m}($s)] : []), ...(self::$extensions[$domain] ?? [])];
        foreach ($checks as $check) {
            try {
                foreach ($check($section) as $r) {
                    $rows[$r['item']] = $r;
                }
            } catch (Throwable $e) {
                $rows['_check'] = $this->row($domain, '_check', null, DataStatus::UNVERIFIED, ['Readiness check failed: '.$e->getMessage()], 'computed');
            }
        }

        return array_values($rows);
    }

    /** @return array{version: string, domains: int, items: int, production_ready: int, by_status: array<string, int>, by_domain: list<array<string, mixed>>} */
    public function summary(?array $items = null): array
    {
        $items ??= $this->items();
        $by = array_fill_keys(DataStatus::CODES, 0);
        $domains = [];
        foreach ($items as $i) {
            $by[$i['status']]++;
            $d = &$domains[$i['domain']];
            $d ??= ['domain' => $i['domain'], 'owner' => $i['owner'], 'items' => 0, 'production_ready' => 0, 'worst' => DataStatus::VERIFIED, 'missing' => 0];
            $d['items']++;
            $d['production_ready'] += $i['production_usable'] ? 1 : 0;
            $d['missing'] += count($i['missing']);
            if (self::rank($i['status']) > self::rank($d['worst'])) {
                $d['worst'] = $i['status'];
            }
            unset($d);
        }

        return ['version' => $this->master->version(), 'domains' => count($domains), 'items' => count($items),
            'production_ready' => count(array_filter($items, fn ($i) => $i['production_usable'])), 'by_status' => $by, 'by_domain' => array_values($domains)];
    }

    /** Severity order used for the worst status of a domain. */
    public static function rank(string $status): int
    {
        return array_search($status, [DataStatus::VERIFIED, DataStatus::PLATFORM_NORMALIZED, DataStatus::RETIRED, DataStatus::UNVERIFIED,
            DataStatus::DEMO_ONLY, DataStatus::CONFIG_REQUIRED, DataStatus::PENDING_SOURCE], true) ?: 0;
    }

    /** @param list<string> $missing */
    public function row(string $domain, string $item, ?string $declared, string $status, array $missing, string $evidence, ?string $source = null): array
    {
        $status = DataStatus::normalize($status);

        return ['domain' => $domain, 'item' => $item, 'owner' => self::OWNERS[$domain] ?? 'Unassigned', 'declared_status' => $declared,
            'status' => $status, 'production_usable' => in_array($status, DataStatus::PRODUCTION, true), 'missing' => array_values($missing),
            'evidence' => $evidence, 'source' => $source ?? DataStatus::SOURCE];
    }

    /** Declared rows straight from the owner's file: `status`, every `*_status` key, and every owner list. */
    private function generic(string $domain, array $section): array
    {
        $rows = [];
        $sectionStatus = is_string($section['status'] ?? null) ? $section['status'] : null;
        foreach ($section as $key => $value) {
            if ($key === 'status' && is_string($value)) {
                $rows[] = $this->row($domain, 'configuration', $value, $value, $this->missingFor($value), 'declared in the data master');
            } elseif (str_ends_with((string) $key, '_status') && is_string($value)) {
                $item = substr((string) $key, 0, -7);
                $rows[] = $this->row($domain, $item, $value, $value, $this->missingFor($value), 'declared in the data master');
            } elseif (is_array($value) && array_is_list($value)) {
                $rows[] = $this->row($domain, (string) $key, $sectionStatus, DataStatus::PLATFORM_NORMALIZED, [], 'owner list ('.count($value).' values); reconciliation owned by the domain module');
            } elseif (is_array($value) && isset($value['status']) && is_string($value['status'])) {
                $rows[] = $this->row($domain, (string) $key, $value['status'], $value['status'], $this->missingFor($value['status']), 'declared in the data master');
            }
        }

        return $rows;
    }

    private function missingFor(string $declared): array
    {
        return match (DataStatus::normalize($declared)) {
            DataStatus::PENDING_SOURCE => ['Official / owner source not provided'],
            DataStatus::CONFIG_REQUIRED => ['Tenant / insurer configuration not done'],
            DataStatus::UNVERIFIED => ['Verification against the source not done'],
            DataStatus::DEMO_ONLY => ['Demo values only'],
            default => [],
        };
    }

    private function has(string $table, ?string $column = null): bool
    {
        return Schema::hasTable($table) && ($column === null || Schema::hasColumn($table, $column));
    }

    // ================================================================== regulatory

    /** Owner product_mapping_rules key => [line_code, coverage codes, CIMA branch numbers expected, note]. */
    public const PRODUCT_MAPPING_CROSSWALK = [
        'TRAVEL_MEDICAL' => ['TRAVEL', ['MEDICAL'], [2]],
        'TRAVEL_ASSISTANCE' => ['TRAVEL', ['ASSISTANCE', 'REPATRIATION'], [18]],
        'TRAVEL_CANCELLATION' => ['TRAVEL', ['CANCELLATION'], [16]],
        'MOTOR_OWN_DAMAGE_THEFT_FIRE' => ['MOTOR', ['OWN_DAMAGE', 'THEFT_FIRE'], [3]],
        'HOME_PROPERTY_FIRE' => ['HOME', ['FIRE', 'WATER_DAMAGE', 'THEFT'], [8, 9]],
        'HOME_HOUSEHOLD_LIABILITY' => ['HOME', ['LIABILITY'], [13]],
        'BUSINESS_INTERRUPTION' => ['BUSINESS', ['BUSINESS_INTERRUPTION'], [16]],
        // Owner decision 6: complementary cover on "its life branch (20/21)" — 21 applies when the principal branch is 21.
        'LIFE_DISABILITY_COMPLEMENTARY' => ['LIFE', ['DISABILITY'], [20]],
    ];

    private function checkRegulatory(array $s): array
    {
        $out = [];
        // cima_branches — canonical insurance_branches (owner decision 16).
        $expected = array_map(fn ($b) => (int) $b[0], (array) ($s['cima_branches'] ?? []));
        $present = $this->has('insurance_branches') ? DB::table('insurance_branches')->where('regime', 'CIMA')->where('reserved', false)->distinct()->pluck('number')->map(fn ($n) => (int) $n)->all() : [];
        $missing = array_values(array_diff($expected, $present));
        $out[] = $this->row('regulatory', 'cima_branches', null, $present === [] ? DataStatus::PENDING_SOURCE : ($missing === [] ? DataStatus::VERIFIED : DataStatus::UNVERIFIED),
            array_map(fn ($n) => sprintf('CIMA branch %02d not in insurance_branches', $n), $missing), count($present).' CIMA branches in insurance_branches (seeded from the CIMA regulatory master)', 'insurance_branches');

        // product_mapping_rules — coverage-level regulatory_class_defaults (CimaCoverageRules).
        $miss = [];
        if ($this->has('regulatory_class_defaults')) {
            foreach (self::PRODUCT_MAPPING_CROSSWALK as $key => [$line, $coverages, $numbers]) {
                $mapped = DB::table('regulatory_class_defaults as d')->join('insurance_branches as b', 'b.code', '=', 'd.branch_code')
                    ->where('d.line_code', $line)->whereIn('d.requires_coverage_code', $coverages)->where('d.status', 'ACTIVE')->distinct()->pluck('b.number')->map(fn ($n) => (int) $n)->all();
                foreach (array_diff($numbers, $mapped) as $n) {
                    $miss[] = sprintf('%s → CIMA %02d not mapped', $key, $n);
                }
            }
        } else {
            $miss[] = 'regulatory_class_defaults missing';
        }
        $out[] = $this->row('regulatory', 'product_mapping_rules', null, $miss === [] ? DataStatus::PLATFORM_NORMALIZED : DataStatus::CONFIG_REQUIRED, $miss,
            'coverage-level rules in regulatory_class_defaults (CimaCoverageRules, owner decisions 1-6)', 'regulatory_class_defaults');

        // insurer_authorizations — never invented; VERIFIED only from regulator evidence (maker-checker).
        $declared = (string) data_get($s, 'insurer_authorizations.status');
        $verified = $this->has('insurer_regulatory_authorizations', 'verification_status')
            ? DB::table('insurer_regulatory_authorizations')->where('status', 'ACTIVE')->where('verification_status', 'VERIFIED')->distinct()->pluck('carrier_id')->all() : [];
        $carriers = $this->has('carriers') ? DB::table('carriers')->when(Schema::hasColumn('carriers', 'is_demo'), fn ($q) => $q->where('is_demo', false))->pluck('id')->all() : [];
        $without = array_diff($carriers, $verified);
        $out[] = $this->row('regulatory', 'insurer_authorizations', $declared, $verified === [] ? DataStatus::PENDING_SOURCE : ($without === [] ? DataStatus::VERIFIED : DataStatus::UNVERIFIED),
            $without === [] ? [] : [count($without).' insurer(s) without a verified CIMA authorization — new product publication blocked (BLOCK_NEW_PRODUCT_PUBLICATION_IF_UNKNOWN)'],
            count($verified).' insurer(s) with a VERIFIED authorization; CimaPublicationGuard blocks the others', 'insurer_regulatory_authorizations');

        // taxes_levies_fees — ChargeTableService: only OWNER_CONFIRMED rates are production values.
        $declared = (string) data_get($s, 'taxes_levies_fees.status');
        $confirmed = 0;
        $unconfirmed = 0;
        foreach (['tax_levy_versions', 'fee_schedule_versions'] as $t) {
            if ($this->has($t, 'data_status')) {
                $confirmed += DB::table($t)->where('data_status', 'OWNER_CONFIRMED')->count();
                $unconfirmed += DB::table($t)->where('data_status', '<>', 'OWNER_CONFIRMED')->whereNotIn('status', ['REJECTED', 'SUPERSEDED', 'EXPIRED'])->count();
            }
        }
        $out[] = $this->row('regulatory', 'taxes_levies_fees', $declared, $confirmed === 0 ? DataStatus::PENDING_SOURCE : ($unconfirmed === 0 ? DataStatus::VERIFIED : DataStatus::UNVERIFIED),
            array_filter([$confirmed === 0 ? 'No tax / levy / fee table verified from a legal source' : null, $unconfirmed > 0 ? "{$unconfirmed} unverified (DEMO / UNVERIFIED) table version(s)" : null]),
            "{$confirmed} verified table version(s); production_use_allowed only for OWNER_CONFIRMED (ProductionUseGuard)", 'tax_levy_versions, fee_schedule_versions');

        return $out;
    }

    // ================================================================== organization capabilities

    private function checkOrganizationCapabilities(array $s): array
    {
        $unresolved = array_values(array_filter((array) ($s['capabilities'] ?? []), fn ($c) => CapabilityCatalogue::resolve((string) $c) === []));
        $modes = array_values(array_diff((array) ($s['execution_modes'] ?? []), CapabilityCatalogue::EXECUTION_MODES));
        $out = [
            $this->row('organization_capabilities', 'capabilities', null, $unresolved === [] ? DataStatus::PLATFORM_NORMALIZED : DataStatus::CONFIG_REQUIRED,
                array_map(fn ($c) => "{$c} not in CapabilityCatalogue", $unresolved), 'CapabilityCatalogue (+ ALIASES: CLAIMS, DOCUMENT_ISSUANCE, API)', 'CapabilityCatalogue'),
            $this->row('organization_capabilities', 'execution_modes', null, $modes === [] ? DataStatus::PLATFORM_NORMALIZED : DataStatus::CONFIG_REQUIRED,
                array_map(fn ($m) => "{$m} not an execution mode", $modes), 'CapabilityCatalogue::EXECUTION_MODES', 'CapabilityCatalogue'),
        ];
        $carriers = $this->has('carriers') ? DB::table('carriers')->when(Schema::hasColumn('carriers', 'is_demo'), fn ($q) => $q->where('is_demo', false))->pluck('id')->all() : [];
        $profiled = $this->has('carrier_capability_profiles') ? DB::table('carrier_capability_profiles')->where('status', 'ACTIVE')->distinct()->pluck('carrier_id')->all() : [];
        $without = array_diff($carriers, $profiled);
        $out[] = $this->row('organization_capabilities', 'configuration', (string) ($s['status'] ?? ''), $profiled !== [] && $without === [] ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
            $without === [] && $profiled !== [] ? [] : [count($without).' insurer(s) without an ACTIVE capability profile'], count($profiled).' insurer(s) with an approved capability profile', 'carrier_capability_profiles');

        return $out;
    }

    // ================================================================== broker / insurer agreements

    /** Owner required field => [table, column] implementing it. */
    public const AGREEMENT_FIELD_MAP = [
        'broker_id' => ['carrier_broker_agreements', 'partner_id'], 'insurer_id' => ['carrier_broker_agreements', 'carrier_id'],
        'agreement_number' => ['carrier_broker_agreements', 'agreement_number'], 'effective_from' => ['carrier_broker_agreements', 'effective_from'],
        'effective_until' => ['carrier_broker_agreements', 'effective_until'], 'authorized_products' => ['carrier_broker_agreement_products', 'insurance_product_id'],
        'quote_rights' => ['carrier_broker_agreement_products', 'can_quote'], 'bind_rights' => ['carrier_broker_agreement_products', 'can_bind'],
        'premium_collection_rights' => ['carrier_broker_agreement_products', 'can_collect_premium'], 'document_issuance_rights' => ['carrier_broker_agreement_products', 'can_issue_documents'],
        'policy_servicing_rights' => ['carrier_broker_agreement_products', 'can_service_policies'], 'claims_assistance_rights' => ['carrier_broker_agreement_products', 'can_assist_claims'],
        'commission_rules' => ['carrier_broker_agreement_products', 'commission_rule_version_id'], 'settlement_terms' => ['carrier_broker_agreements', 'settlement_terms'],
        'territory' => ['carrier_broker_agreements', 'territories'], 'status' => ['carrier_broker_agreements', 'status'], 'source_document' => ['carrier_broker_agreements', 'source_document'],
    ];

    private function checkBrokerInsurerAgreements(array $s): array
    {
        $missingCols = [];
        foreach ((array) ($s['required_fields'] ?? []) as $f) {
            [$t, $c] = self::AGREEMENT_FIELD_MAP[$f] ?? [null, null];
            if ($t === null || ! $this->has($t, $c)) {
                $missingCols[] = "{$f} has no column";
            }
        }
        $active = $this->has('carrier_broker_agreements') ? DB::table('carrier_broker_agreements')->where('status', 'ACTIVE')->count() : 0;
        $noSource = $this->has('carrier_broker_agreements', 'source_document') ? DB::table('carrier_broker_agreements')->where('status', 'ACTIVE')->whereNull('source_document')->count() : 0;
        $noTerms = $this->has('carrier_broker_agreements', 'settlement_terms') ? DB::table('carrier_broker_agreements')->where('status', 'ACTIVE')->whereNull('settlement_terms')->count() : 0;

        return [
            $this->row('broker_insurer_agreements', 'required_fields', null, $missingCols === [] ? DataStatus::PLATFORM_NORMALIZED : DataStatus::CONFIG_REQUIRED, $missingCols,
                'carrier_broker_agreements + carrier_broker_agreement_products', 'carrier_broker_agreements'),
            $this->row('broker_insurer_agreements', 'configuration', (string) ($s['status'] ?? ''), $active === 0 ? DataStatus::CONFIG_REQUIRED : ($noSource + $noTerms === 0 ? DataStatus::VERIFIED : DataStatus::UNVERIFIED),
                array_filter([$active === 0 ? 'No ACTIVE broker/insurer agreement' : null, $noSource ? "{$noSource} active agreement(s) without source_document" : null, $noTerms ? "{$noTerms} active agreement(s) without settlement_terms" : null]),
                "{$active} active agreement(s)", 'carrier_broker_agreements'),
        ];
    }

    // ================================================================== authority

    private function checkAuthority(array $s): array
    {
        $active = $this->has('authority_types') ? app(AuthorityTypeCatalogue::class)->all(true)->pluck('code')->all() : [];
        $missing = array_values(array_diff((array) ($s['authority_types'] ?? []), $active));
        $limits = $this->has('authority_limits') ? DB::table('authority_limits')->where('status', 'ACTIVE')->count() : 0;

        return [
            $this->row('authority', 'authority_types', null, $missing === [] ? DataStatus::PLATFORM_NORMALIZED : DataStatus::CONFIG_REQUIRED, array_map(fn ($c) => "{$c} not an active authority type", $missing),
                count($active).' active authority types', 'authority_types'),
            $this->row('authority', 'configuration', (string) ($s['status'] ?? ''), $limits > 0 ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED, $limits > 0 ? [] : ['No ACTIVE authority limit configured'],
                "{$limits} active authority limit(s)", 'authority_limits'),
        ];
    }

    // ================================================================== KYC / AML

    private function checkKycAml(array $s): array
    {
        $cfg = (array) config('kyc.risk.factors', []);
        $unmapped = [];
        $unconfigured = [];
        foreach ((array) ($s['risk_dimensions'] ?? []) as $d) {
            $f = CustomerRiskRatingService::DIMENSIONS[$d] ?? null;
            if ($f === null) {
                $unmapped[] = "{$d} has no risk factor";
            } elseif (($cfg[$f]['weight'] ?? null) === null) {
                $unconfigured[] = "{$d} weight not configured (kyc.risk.factors.{$f})";
            }
        }
        $modeRows = [];
        foreach ((array) ($s['screening_modes'] ?? []) as $m) {
            if (! in_array($m, ScreeningMode::MODES, true)) {
                $modeRows[] = "{$m} not a supported screening mode";
            } elseif (ScreeningMode::configurationStatus($m) === DataStatus::CONFIG_REQUIRED) {
                $modeRows[] = "{$m} supported but no provider configured";
            }
        }
        $levels = array_values(array_filter((array) ($s['kyc_levels'] ?? []), fn ($l) => ! in_array(KycRequirementService::normalizeLevel((string) $l), KycRequirementService::LEVELS, true)));
        $reqs = $this->has('kyc_level_requirements') ? DB::table('kyc_level_requirements')->where('status', 'ACTIVE')->get(['source']) : collect();
        $reqStatus = $reqs->isEmpty() ? DataStatus::CONFIG_REQUIRED : ($reqs->every(fn ($r) => DataStatus::isProduction($r->source)) ? DataStatus::VERIFIED : DataStatus::UNVERIFIED);
        $refresh = array_filter([...array_values((array) config('kyc.refresh_months', [])), ...array_values((array) config('kyc.risk.refresh_months', []))], fn ($v) => $v !== null);
        $refreshDb = $this->has('kyc_level_requirements') && DB::table('kyc_level_requirements')->where('status', 'ACTIVE')->whereNotNull('refresh_months')->exists();
        $provider = filled(config('kyc.screening.provider'));

        return [
            $this->row('kyc_aml', 'risk_dimensions', null, $unmapped !== [] || $unconfigured !== [] ? DataStatus::CONFIG_REQUIRED : DataStatus::VERIFIED, [...$unmapped, ...$unconfigured],
                'CustomerRiskRatingService::DIMENSIONS (OCCUPATION_RISK optional until configured)', 'config/kyc.php kyc.risk'),
            $this->row('kyc_aml', 'screening_modes', null, DataStatus::PLATFORM_NORMALIZED, $modeRows, 'ScreeningMode::MODES — MANUAL_AUDITED operational; automated screening never claimed', 'ScreeningMode'),
            $this->row('kyc_aml', 'beneficial_ownership', (string) data_get($s, 'beneficial_ownership.status'), DataStatus::VERIFIED, [],
                'PartyRelationshipService UBO graph: more than 25 % or control by other means', 'PartyRelationshipService'),
            $this->row('kyc_aml', 'kyc_levels', null, $levels === [] ? DataStatus::PLATFORM_NORMALIZED : DataStatus::CONFIG_REQUIRED, array_map(fn ($l) => "{$l} not a KYC level", $levels),
                'KycRequirementService::LEVELS (BASIC = SIMPLIFIED alias)', 'KycRequirementService'),
            $this->row('kyc_aml', 'required_document_matrix', (string) ($s['required_document_matrix_status'] ?? ''), $reqStatus,
                $reqStatus === DataStatus::VERIFIED ? [] : [$reqs->isEmpty() ? 'No KYC level requirement configured' : 'KYC level requirements are platform defaults (PLATFORM_DEFAULT_UNVERIFIED)'], $reqs->count().' active kyc_level_requirements', 'kyc_level_requirements'),
            $this->row('kyc_aml', 'refresh_periods', (string) ($s['refresh_periods_status'] ?? ''), $refresh !== [] || $refreshDb ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
                $refresh !== [] || $refreshDb ? [] : ['No refresh period configured (kyc.refresh_months / kyc.risk.refresh_months)'], 'config kyc.refresh_months, kyc.risk.refresh_months', 'config/kyc.php'),
            $this->row('kyc_aml', 'pep_provider', (string) ($s['pep_provider_status'] ?? ''), $provider ? DataStatus::UNVERIFIED : DataStatus::CONFIG_REQUIRED, $provider ? [] : ['No PEP screening provider configured'], 'kyc.screening.provider', 'config/kyc.php'),
            $this->row('kyc_aml', 'sanctions_provider', (string) ($s['sanctions_provider_status'] ?? ''), $provider ? DataStatus::UNVERIFIED : DataStatus::CONFIG_REQUIRED, $provider ? [] : ['No sanctions screening provider configured'], 'kyc.screening.provider', 'config/kyc.php'),
        ];
    }

    // ================================================================== SLA calendars

    private function checkSlaCalendars(array $s): array
    {
        $calendars = $this->has('business_calendars') ? DB::table('business_calendars')->count() : 0;
        $holidays = $this->has('reference_datasets') ? DB::table('reference_datasets')->where('kind', 'PUBLIC_HOLIDAYS')->where('status', 'ACTIVE')->where('verification_status', 'VERIFIED')->count() : 0;
        $defaults = CaseTypeCatalogue::MANUAL_QUOTE_SLA_DEFAULTS;
        $ok = ($defaults[0]['target_business_minutes'] ?? null) === 240 && ($defaults[1]['target_business_days'] ?? null) === 2 && ($defaults[2]['target_business_days'] ?? null) === 5;

        return [
            $this->row('sla_calendars', 'configuration', (string) ($s['status'] ?? ''), $calendars > 0 ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
                array_filter([$calendars === 0 ? 'No business calendar configured' : null, $holidays === 0 ? 'No VERIFIED public-holiday dataset (holidays not counted)' : null]),
                "{$calendars} business calendar(s); timezone default ".($s['timezone'] ?? 'Africa/Douala'), 'business_calendars, reference_datasets'),
            $this->row('sla_calendars', 'platform_defaults', null, $ok ? DataStatus::PLATFORM_NORMALIZED : DataStatus::CONFIG_REQUIRED, $ok ? [] : ['Manual quote SLA defaults differ from the owner defaults'],
                'CaseTypeCatalogue::MANUAL_QUOTE_SLA_DEFAULTS (4 business hours / 2 / 5 business days, PLATFORM_SLA)', 'CaseTypeCatalogue'),
        ];
    }

    // ================================================================== case management

    private function checkCaseManagement(array $s): array
    {
        $families = $this->has('case_families') ? DB::table('case_families')->where('active', true)->pluck('code')->all() : [];
        $missing = array_map(fn ($f) => "{$f} not an active case family", array_values(array_diff((array) ($s['case_families'] ?? []), $families)));
        if ($this->has('case_types')) {
            foreach (DB::table('case_types')->whereNotIn('family_code', (array) ($s['case_families'] ?? []))->orWhereNull('family_code')->distinct()->pluck('code') as $code) {
                $missing[] = "case type {$code} is not in an owner family";
            }
        }
        $prio = array_values(array_diff((array) ($s['priorities'] ?? []), CaseTypeCatalogue::PRIORITIES));

        return [
            $this->row('case_management', 'case_families', null, $missing === [] ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED, $missing, 'case_families (owner list replaces the reconstructed families)', 'case_families'),
            $this->row('case_management', 'priorities', null, $prio === [] ? DataStatus::PLATFORM_NORMALIZED : DataStatus::CONFIG_REQUIRED, array_map(fn ($p) => "{$p} not a case priority", $prio), 'CaseTypeCatalogue::PRIORITIES', 'cases.priority'),
        ];
    }

    // ================================================================== notifications

    private function checkNotifications(array $s): array
    {
        $out = [];
        foreach (['channels' => NotificationVocabulary::CHANNELS, 'delivery_statuses' => NotificationVocabulary::DELIVERY_STATUSES, 'consent_types' => NotificationVocabulary::CONSENT_TYPES] as $key => $list) {
            $miss = array_values(array_diff((array) ($s[$key] ?? []), $list));
            $out[] = $this->row('notifications', $key, null, $miss === [] ? DataStatus::PLATFORM_NORMALIZED : DataStatus::CONFIG_REQUIRED, array_map(fn ($v) => "{$v} not supported", $miss), "NotificationVocabulary::".strtoupper($key), 'NotificationVocabulary');
        }

        return $out;
    }

    // ================================================================== complaints

    private function checkComplaints(array $s): array
    {
        $miss = array_values(array_diff((array) ($s['severity_levels'] ?? []), array_keys(CaseTypeCatalogue::COMPLAINT_SEVERITY_MAP)));
        $regulatory = $this->has('sla_policy_overrides') ? DB::table('sla_policy_overrides')->where('case_type_code', 'COMPLAINT')->where('deadline_label', 'REGULATORY_DEADLINE')->whereNotNull('legal_basis')->count() : 0;
        $platform = $this->has('case_types') ? DB::table('case_types')->where('code', 'COMPLAINT')->where('status', 'EFFECTIVE')->where('sla_policies', '!=', '[]')->exists() : false;

        return [
            $this->row('complaints', 'severity_levels', null, $miss === [] ? DataStatus::PLATFORM_NORMALIZED : DataStatus::CONFIG_REQUIRED, $miss, 'CaseTypeCatalogue::COMPLAINT_SEVERITY_MAP → case priority', 'CaseTypeCatalogue'),
            $this->row('complaints', 'regulatory_deadline', (string) ($s['regulatory_deadline_status'] ?? ''), $regulatory > 0 ? DataStatus::VERIFIED : DataStatus::PENDING_SOURCE,
                $regulatory > 0 ? [] : ['No REGULATORY_DEADLINE with a legal basis for COMPLAINT'], "{$regulatory} regulatory deadline override(s)", 'sla_policy_overrides'),
            $this->row('complaints', 'platform_sla', (string) ($s['platform_sla_status'] ?? ''), $platform ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
                $platform ? [] : ['COMPLAINT case type has no PLATFORM_SLA target'], 'COMPLAINT case type sla_policies', 'case_types'),
        ];
    }

    // ================================================================== fraud controls

    private function checkFraudControls(array $s): array
    {
        $rules = $this->has('fraud_rule_versions') ? DB::table('fraud_rule_versions')->where('status', 'ACTIVE')->count() : 0;

        return [
            $this->row('fraud_controls', 'decision_rule', (string) ($s['decision_rule'] ?? ''), DataStatus::VERIFIED, [],
                'Indicators are not determinations: fraud rules only open risk_alerts (OPEN); a human reviewer other than the assignee records the decision (FraudReviewService::decide). No claim is refused automatically.', 'FraudReviewService'),
            $this->row('fraud_controls', 'indicator_catalogue', (string) ($s['indicator_catalogue_status'] ?? ''), $rules > 0 ? DataStatus::UNVERIFIED : DataStatus::PENDING_SOURCE,
                $rules > 0 ? ['Indicator catalogue source not recorded'] : ['No fraud indicator source'], "{$rules} active fraud rule version(s)", 'fraud_rule_versions'),
        ];
    }

    // ================================================================== regulatory reporting

    private function checkRegulatoryReporting(array $s): array
    {
        $defs = $this->has('regulatory_report_definitions') ? DB::table('regulatory_report_definitions')->where('status', 'ACTIVE')->count() : 0;

        return [$this->row('regulatory_reporting', 'report_dictionary', (string) ($s['report_dictionary_status'] ?? ''), $defs > 0 ? DataStatus::UNVERIFIED : DataStatus::PENDING_SOURCE,
            $defs > 0 ? ['Report definitions not verified against the CIMA report dictionary'] : ['CIMA report dictionary not provided'], "{$defs} active report definition(s)", 'regulatory_report_definitions')];
    }

    // ================================================================== documents

    private function checkDocuments(array $s): array
    {
        $classes = array_values(array_filter((array) ($s['confidentiality_classes'] ?? []), fn ($c) => ! in_array(DocumentRegister::CONFIDENTIALITY_CLASS_MAP[$c] ?? null, DocumentRegister::SECURITY_LEVELS, true)));
        $seals = array_values(array_diff((array) ($s['seal_types'] ?? []), DocumentRegister::SEAL_TYPES));
        $sigs = array_values(array_filter((array) ($s['signature_types'] ?? []), fn ($t) => (DocumentRegister::SIGNATURE_TYPE_MAP[$t] ?? null) === null));
        $types = $this->has('document_types') ? DB::table('document_types')->count() : 0;
        $expected = (int) ($s['canonical_document_count'] ?? 0);
        $numbering = $this->has('document_numbering_families') ? DB::table('document_numbering_families')->count() : 0;

        return [
            $this->row('documents', 'confidentiality_classes', null, $classes === [] ? DataStatus::PLATFORM_NORMALIZED : DataStatus::CONFIG_REQUIRED, array_map(fn ($c) => "{$c} has no security level", $classes),
                'DocumentRegister::CONFIDENTIALITY_CLASS_MAP → security_level', 'DocumentRegister'),
            $this->row('documents', 'seal_types', null, DataStatus::CONFIG_REQUIRED, [...array_map(fn ($v) => "{$v} unknown", $seals), 'No seal artwork / keys configured'], 'DocumentRegister::SEAL_TYPES (vocabulary)', 'DocumentRegister'),
            $this->row('documents', 'signature_types', null, $sigs === [] ? DataStatus::PLATFORM_NORMALIZED : DataStatus::CONFIG_REQUIRED, array_map(fn ($t) => "{$t} has no signature mode", $sigs),
                'DocumentRegister::SIGNATURE_TYPE_MAP → document_issuance_profiles.signature_mode', 'DocumentRegister'),
            $this->row('documents', 'canonical_documents', null, $types >= $expected && $types > 0 ? DataStatus::PLATFORM_NORMALIZED : DataStatus::CONFIG_REQUIRED,
                $types >= $expected && $types > 0 ? [] : ["{$types} of {$expected} canonical document types loaded"], "{$types} document types", 'document_types'),
            $this->row('documents', 'numbering_profiles', (string) ($s['numbering_profiles_status'] ?? ''), $numbering > 0 ? DataStatus::PLATFORM_NORMALIZED : DataStatus::CONFIG_REQUIRED,
                $numbering > 0 ? [] : ['No numbering family configured'], "{$numbering} numbering famil(ies)", 'document_numbering_families'),
        ];
    }

    // ================================================================== document requirement rules

    private function checkDocumentRequirementRules(array $s): array
    {
        $types = array_values(array_diff((array) ($s['requirement_types'] ?? []), ProductDocumentRequirementService::REQUIREMENTS));
        $stages = array_values(array_filter((array) ($s['lifecycle_stages'] ?? []), fn ($st) => (ProductDocumentRequirementService::LIFECYCLE_STAGE_MAP[$st] ?? []) === []));

        return [
            $this->row('document_requirement_rules', 'requirement_types', null, $types === [] ? DataStatus::PLATFORM_NORMALIZED : DataStatus::CONFIG_REQUIRED, array_map(fn ($t) => "{$t} unknown", $types),
                'ProductDocumentRequirementService::REQUIREMENTS', 'ProductDocumentRequirementService'),
            $this->row('document_requirement_rules', 'lifecycle_stages', null, $stages === [] ? DataStatus::PLATFORM_NORMALIZED : DataStatus::CONFIG_REQUIRED, array_map(fn ($st) => "{$st} has no catalogue stage / pack", $stages),
                'ProductDocumentRequirementService::LIFECYCLE_STAGE_MAP → document_packs.lifecycle_stage', 'document_packs'),
        ];
    }
}
