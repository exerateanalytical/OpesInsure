<?php

declare(strict_types=1);

namespace App\Application\Compliance\Catalogue;

use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\DataReadiness\DataStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Data Readiness gates for Gap Closure Pack 08 / 09 (computed from the catalogue tables, never declared). */
final class ComplianceCatalogueReadiness
{
    public static function register(): void
    {
        DataReadinessRegistry::extend('kyc_aml', fn () => self::kyc());
        DataReadinessRegistry::extend('fraud_controls', fn () => self::fraud());
        DataReadinessRegistry::extend('regulatory_reporting', fn () => self::reporting());
        DataReadinessRegistry::extend('ict_controls', fn () => self::controls('ict_controls', 'ICT', 'cima_010_24_control_taxonomy', 'CIMA Reg. 010-24'));
    }

    private static function reg(): DataReadinessRegistry
    {
        return app(DataReadinessRegistry::class);
    }

    public static function kyc(): array
    {
        if (! Schema::hasTable('kyc_document_matrix')) {
            return [];
        }
        $r = self::reg();
        $p8 = ComplianceCatalogueSeeder::pack('08');
        $types = DB::table('kyc_document_matrix')->distinct()->pluck('customer_type')->all();
        $missingTypes = array_values(array_diff((array) $p8['customer_types'], $types));
        $verifiedDocs = DB::table('kyc_document_matrix')->where('data_status', DataStatus::VERIFIED)->count();
        $refresh = DB::table('kyc_refresh_policies')->whereNotNull('tenant_id')->where('data_status', DataStatus::VERIFIED)->distinct()->count('tenant_id');
        $country = DB::table('country_risk_ratings')->where('data_status', DataStatus::VERIFIED)->count();
        $lists = Schema::hasTable('screening_list_versions') ? DB::table('screening_list_versions')->where('status', 'ACTIVE')->count() : 0;

        return [
            $r->row('kyc_aml', 'kyc_document_matrix_by_customer_type', (string) data_get($p8, 'kyc_document_matrix.status'), $verifiedDocs > 0 && $missingTypes === [] ? DataStatus::VERIFIED : DataStatus::UNVERIFIED,
                [...($verifiedDocs > 0 ? [] : ['Baseline matrix requires legal / tenant validation']), ...array_map(fn ($t) => "No document baseline for {$t}", $missingTypes)],
                DB::table('kyc_document_matrix')->count().' baseline rows in kyc_document_matrix', 'kyc_document_matrix'),
            $r->row('kyc_aml', 'kyc_refresh_policies', 'CONFIG_REQUIRED', $refresh > 0 ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
                $refresh > 0 ? [] : ['No tenant has an approved KYC refresh policy (refresh_months + source_policy_id)'], "{$refresh} tenant(s) with approved refresh policies", 'kyc_refresh_policies'),
            $r->row('kyc_aml', 'country_risk', 'CONFIG_REQUIRED', $country > 0 ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
                $country > 0 ? [] : ['Country-risk catalogue not imported (import target country_risk_ratings)'], "{$country} verified country ratings", 'country_risk_ratings'),
            $r->row('kyc_aml', 'pep_sanctions_lists', 'CONFIG_REQUIRED', $lists > 0 ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
                $lists > 0 ? [] : ['No PEP / sanctions list imported and approved (/api/v1/aml/screening/lists); no list is bundled or invented'], "{$lists} active screening list version(s)", 'screening_list_versions'),
            ...self::controls('kyc_aml', 'AML', 'aml_control_catalogue', 'AML/CFT regulation'),
        ];
    }

    public static function fraud(): array
    {
        if (! Schema::hasTable('fraud_indicators')) {
            return [];
        }
        $n = DB::table('fraud_indicators')->count();
        $unlinked = DB::table('fraud_indicators')->whereNull('linked_rule_code')->pluck('code')->all();

        return [
            self::reg()->row('fraud_controls', 'indicator_catalogue', 'BASELINE_POPULATED', $n > 0 ? DataStatus::PLATFORM_NORMALIZED : DataStatus::PENDING_SOURCE, [],
                "{$n} indicators in fraud_indicators (outcome REVIEW_REQUIRED; is_determination = false enforced)", 'fraud_indicators'),
            self::reg()->row('fraud_controls', 'indicator_detection_rules', null, $unlinked === [] ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
                array_map(fn ($c) => "{$c}: no detection rule (fraud_rule_versions) linked; manual flagging only", $unlinked), 'linked_rule_code on fraud_indicators', 'fraud_indicators'),
        ];
    }

    public static function reporting(): array
    {
        if (! Schema::hasTable('regulatory_report_dictionary_lines')) {
            return [];
        }
        $reports = DB::table('regulatory_report_dictionary_lines')->where('data_status', DataStatus::VERIFIED)->distinct()->count('report_code');

        return [self::reg()->row('regulatory_reporting', 'report_dictionary', 'PENDING_FORM_BY_FORM_OFFICIAL_IMPORT', $reports > 0 ? DataStatus::VERIFIED : DataStatus::PENDING_SOURCE,
            $reports > 0 ? [] : ['No official report form imported (import target regulatory_report_dictionary_lines)'], "{$reports} report(s) with verified lines", 'regulatory_report_dictionary_lines')];
    }

    public static function controls(string $domain, string $fw, string $item, string $source): array
    {
        if (! Schema::hasTable('compliance_controls')) {
            return [];
        }
        $pending = DB::table('compliance_controls')->where('framework', $fw)->where('data_status', '!=', DataStatus::VERIFIED)->pluck('control_code')->all();
        $total = DB::table('compliance_controls')->where('framework', $fw)->count();

        return [self::reg()->row($domain, $item, 'CONTROL_DOMAINS_POPULATED', $total > 0 && $pending === [] ? DataStatus::VERIFIED : DataStatus::PENDING_SOURCE,
            $pending === [] ? [] : [count($pending)." of {$total} {$fw} controls without verified requirement text ({$source}); rated assessments blocked"],
            "{$total} {$fw} control codes in compliance_controls", 'compliance_controls')];
    }
}
