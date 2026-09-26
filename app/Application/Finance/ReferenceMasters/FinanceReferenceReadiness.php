<?php

declare(strict_types=1);

namespace App\Application\Finance\ReferenceMasters;

use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\DataReadiness\DataStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agent GP6 — computed Data Readiness rows for the gap-pack 06 gates (payments_finance + accounting domains). A gate is only
 * production-usable when the data actually exists with a production status; declared statuses never promote themselves.
 */
final class FinanceReferenceReadiness
{
    public static function register(): void
    {
        DataReadinessRegistry::extend('payments_finance', fn (array $s) => self::payments());
        DataReadinessRegistry::extend('accounting', fn (array $s) => self::accounting());
    }

    private static function reg(): DataReadinessRegistry
    {
        return app(DataReadinessRegistry::class);
    }

    /** @return list<array<string, mixed>> */
    public static function payments(): array
    {
        if (! Schema::hasTable('financial_institutions')) {
            return [];
        }
        $r = self::reg();
        $banks = DB::table('financial_institutions')->where('institution_type', 'BANK');
        $usable = (clone $banks)->whereIn('verification_status', FinanceReferenceCatalogue::PRODUCTION_STATUSES)->count();
        $pending = (clone $banks)->whereNotIn('verification_status', FinanceReferenceCatalogue::PRODUCTION_STATUSES)->pluck('legal_name')->all();
        $out[] = $r->row('payments_finance', 'banks_master', 'PENDING_SOURCE', $usable === 0 ? DataStatus::PENDING_SOURCE : ($pending === [] ? DataStatus::VERIFIED : DataStatus::UNVERIFIED),
            array_merge($usable === 0 ? ['Current official bank list (BEAC/COBAC) not imported: import target financial_institutions'] : [],
                array_map(fn ($n) => "$n: historical reference pending official import", $pending)),
            "$usable production-usable banks, ".count($pending).' pending (financial_institutions)', 'financial_institutions');

        $pis = DB::table('financial_institutions')->where('institution_type', 'PAYMENT_INSTITUTION')->get();
        $ok = $pis->whereIn('verification_status', FinanceReferenceCatalogue::PRODUCTION_STATUSES)->count();
        $out[] = $r->row('payments_finance', 'mobile_money_provider_master', 'PARTIALLY_KNOWN', $ok > 0 ? DataStatus::VERIFIED : DataStatus::PENDING_SOURCE,
            $ok > 0 ? [] : ['No verified payment institution'], "$ok payment institutions with a verified public source (DGTCFM)", 'financial_institutions');

        $active = DB::table('payment_provider_profiles')->where('status', 'ACTIVE')->distinct()->count('tenant_id');
        $out[] = $r->row('payments_finance', 'payment_provider_config', 'CONFIG_REQUIRED', $active > 0 ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
            $active > 0 ? [] : ['No tenant has an approved payment provider profile (merchant, settlement account, cycle, fees)'],
            "$active tenants with an ACTIVE payment_provider_profiles row; credentials stay in the secrets manager", 'payment_provider_profiles');

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function accounting(): array
    {
        if (! Schema::hasTable('gl_control_account_mappings')) {
            return [];
        }
        $r = self::reg();
        $controls = array_keys(FinanceReferenceCatalogue::CONTROL_ACCOUNTS);
        $noBaseline = array_keys(array_filter(FinanceReferenceCatalogue::CONTROL_ACCOUNTS, fn ($c) => $c === null));
        $tenantsMapped = DB::table('gl_control_account_mappings')->whereNotNull('tenant_id')->where('status', 'APPROVED')
            ->select('tenant_id')->groupBy('tenant_id')->havingRaw('COUNT(DISTINCT control_code) = ?', [count($controls)])->get()->count();
        $out[] = $r->row('accounting', 'chart_of_accounts', 'CONFIG_REQUIRED', $tenantsMapped > 0 ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
            $tenantsMapped > 0 ? [] : array_merge(['No tenant has approved all '.count($controls).' control-account mappings (platform baseline only)'],
                array_map(fn ($c) => "$c has no baseline account", $noBaseline)),
            "$tenantsMapped tenants fully mapped; platform baseline from DefaultChartOfAccounts is PLATFORM_NORMALIZED", 'gl_control_account_mappings');

        $unmapped = array_keys(array_filter(FinanceReferenceCatalogue::GL_EVENTS, fn ($e) => $e === null));
        $tenantEvents = DB::table('accounting_event_mappings')->whereNotNull('tenant_id')->where('status', 'ACTIVE')->distinct()->count('tenant_id');
        $out[] = $r->row('accounting', 'gl_mapping', 'CONFIG_REQUIRED', $unmapped === [] && $tenantEvents > 0 ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
            array_merge(array_map(fn ($e) => "$e: no accounting event catalogued", $unmapped), $tenantEvents > 0 ? [] : ['No tenant-approved event mapping (platform defaults only)']),
            count(FinanceReferenceCatalogue::GL_EVENTS) - count($unmapped).' spec events resolve through accounting_event_mappings', 'accounting_event_mappings');

        $cc = DB::table('cost_centres')->where('status', 'ACTIVE')->distinct()->count('tenant_id');
        $out[] = $r->row('accounting', 'cost_centre', 'CONFIG_REQUIRED', $cc > 0 ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
            $cc > 0 ? [] : ['No tenant has defined cost centres'], "$cc tenants with active cost centres", 'cost_centres');

        return $out;
    }
}
