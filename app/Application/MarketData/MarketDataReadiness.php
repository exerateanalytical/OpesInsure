<?php

declare(strict_types=1);

namespace App\Application\MarketData;

use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\DataReadiness\DataStatus;
use App\Application\MasterData\VehicleMasterSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registers the gap closure 01 gates in the Data Readiness registry (computed from the canonical tables, never
 * from the pack alone): insurer CIMA authorizations, broker directory, broker–insurer agreements, product catalogue,
 * commission tables, taxes/levies and motor usage mapping. Rows are keyed gap01_* so they never collide with the
 * workflow data master items of the same domain.
 */
final class MarketDataReadiness
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        DataReadinessRegistry::extend('regulatory', fn () => app(self::class)->regulatory());
        DataReadinessRegistry::extend('broker_insurer_agreements', fn () => app(self::class)->distribution());
        DataReadinessRegistry::extend('commission', fn () => app(self::class)->commission());
        DataReadinessRegistry::extend('vehicles', fn () => app(self::class)->vehicles());
    }

    /** @return list<array<string, mixed>> */
    public function regulatory(): array
    {
        $reg = app(DataReadinessRegistry::class);
        $pack = MarketDataGates::pack();
        $out = [];

        $carriers = DB::table('carriers')->where('is_official_register', true)->count();
        $verified = DB::table('insurer_regulatory_authorizations')->where('status', 'ACTIVE')->where('verification_status', 'VERIFIED')->distinct()->count('carrier_id');
        $out[] = $reg->row('regulatory', 'gap01_insurer_authorizations', $pack['insurer_authorizations']['status'] ?? null,
            $carriers > 0 && $verified >= $carriers ? DataStatus::VERIFIED : ($verified > 0 ? DataStatus::UNVERIFIED : DataStatus::PENDING_SOURCE),
            $verified >= $carriers && $carriers > 0 ? [] : [($carriers - $verified).' of '.$carriers.' register insurers without a verified CIMA branch authorization (new product publication blocked)'],
            "{$verified}/{$carriers} insurers with verified agrément; import target cima_insurer_authorizations (evidence + maker-checker)", 'insurer_regulatory_authorizations');

        $products = DB::table('insurance_products')->whereIn('status', ['ACTIVE', 'APPROVED'])->count();
        $sourced = DB::table('insurance_products')->whereIn('status', ['ACTIVE', 'APPROVED'])->where('catalogue_data_status', 'VERIFIED')->count();
        $out[] = $reg->row('regulatory', 'gap01_product_catalogue', $pack['product_catalogue']['status'] ?? null,
            $products > 0 && $sourced === $products ? DataStatus::VERIFIED : DataStatus::PENDING_SOURCE,
            $products > 0 && $sourced === $products ? [] : ['Insurer product catalogue source documents not provided ('.$sourced.'/'.$products.' published versions sourced); direct B2C stays disabled'],
            'insurance_products (+ product_class, channels, modes, rules, source_document_ids)', 'insurance_products');

        $taxes = DB::table('tax_levy_versions')->where('status', 'ACTIVE')->get(['verification_status', 'data_status']);
        $ok = $taxes->isNotEmpty() && $taxes->every(fn ($t) => DataStatus::isProduction($t->verification_status ?? $t->data_status));
        $out[] = $reg->row('regulatory', 'gap01_taxes_levies', $pack['taxes_levies_statutory_charges']['status'] ?? null,
            $ok ? DataStatus::VERIFIED : DataStatus::UNVERIFIED, $ok ? [] : ['Taxes, levies and statutory charges pending legal verification (tax_levy_versions verification workflow)'],
            $taxes->count().' active tax/levy versions', 'tax_levy_versions');

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function distribution(): array
    {
        $reg = app(DataReadinessRegistry::class);
        $pack = MarketDataGates::pack();
        $expected = (int) ($pack['broker_directory']['official_population_2026'] ?? 0);
        $brokers = DB::table('partners')->where('type', 'BROKER')->where('is_official_register', true)->count();
        $enriched = Schema::hasColumn('institution_profiles', 'partner_id') ? DB::table('institution_profiles')->whereNotNull('partner_id')->count() : 0;
        $missing = [];
        if ($brokers < $expected) {
            $missing[] = "Only {$brokers} of {$expected} DGTCFM brokers in the register";
        }
        if ($enriched < $brokers) {
            $missing[] = ($brokers - $enriched).' brokers without contact enrichment (import target broker_directory_enrichment)';
        }
        $out[] = $reg->row('broker_insurer_agreements', 'gap01_broker_directory', $pack['broker_directory']['status'] ?? null,
            $brokers >= $expected ? DataStatus::VERIFIED : DataStatus::UNVERIFIED, $missing,
            "{$brokers}/{$expected} DGTCFM brokers; {$enriched} enriched profiles", 'partners + institution_profiles');

        $active = DB::table('carrier_broker_agreements')->where('status', 'ACTIVE')->where('is_demo', false);
        $total = (clone $active)->count();
        $sourced = (clone $active)->where('data_status', 'VERIFIED')->count();
        $out[] = $reg->row('broker_insurer_agreements', 'gap01_agreements', $pack['broker_insurer_agreements']['status'] ?? null,
            $total > 0 && $sourced === $total ? DataStatus::VERIFIED : DataStatus::PENDING_SOURCE,
            $total > 0 && $sourced === $total ? [] : ['Signed broker–insurer agreements not provided ('.$sourced.'/'.$total.' active real agreements verified)'],
            'carrier_broker_agreements (+ contract terms, source document, data_status)', 'carrier_broker_agreements');

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function commission(): array
    {
        $reg = app(DataReadinessRegistry::class);
        $approved = DB::table('commission_rule_versions')->where('status', 'APPROVED');
        $total = (clone $approved)->count();
        $sourced = (clone $approved)->where(fn ($q) => $q->whereNotNull('agreement_id')->orWhereNotNull('source_document'))->count();

        return [$reg->row('commission', 'gap01_commission_tables', MarketDataGates::pack()['commission_tables']['status'] ?? null,
            $total > 0 && $sourced === $total ? DataStatus::VERIFIED : DataStatus::PENDING_SOURCE,
            $total > 0 && $sourced === $total ? [] : ['Commission tables not sourced from agreements ('.$sourced.'/'.$total.' approved rules cite a source); import target commission_tables'],
            'commission_rule_versions (+ beneficiary, basis type, fixed amount, earning event, tax treatment)', 'commission_rule_versions')];
    }

    /** @return list<array<string, mixed>> */
    public function vehicles(): array
    {
        $reg = app(DataReadinessRegistry::class);
        $m = MarketDataGates::pack()['motor_usage_underwriting_mapping'] ?? [];
        $unmapped = [];
        foreach ((array) ($m['usage_codes'] ?? []) as $code) {
            if (! \App\Models\Vehicles\VehicleReferenceValue::where('group', 'usage')->where('code', VehicleMasterSource::canonical('usage', (string) $code))->exists()) {
                $unmapped[] = "Usage {$code} does not resolve to a canonical vehicle usage";
            }
        }

        return [
            $reg->row('vehicles', 'gap01_motor_usage_codes', $m['status'] ?? null, $unmapped === [] ? DataStatus::PLATFORM_NORMALIZED : DataStatus::UNVERIFIED, $unmapped,
                count((array) ($m['usage_codes'] ?? [])).' pack usage codes resolved through VehicleMasterSource', 'master_data:usage'),
            $reg->row('vehicles', 'gap01_motor_usage_insurer_pricing', $m['insurer_specific_pricing_mapping_status'] ?? null, DataStatus::PENDING_SOURCE,
                ['Insurer-specific pricing per usage is the insurer tariff (PENDING_PRIVATE_SOURCE)'], 'declared in the gap closure pack'),
        ];
    }
}
