<?php

declare(strict_types=1);

namespace App\Application\Demo;

use Database\Seeders\DemoInstitutionalSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-SEED-005 / REQ-SEED-001 — computed (never hard-coded) view of the demo layer: which demo records exist per
 * table and workflow status, whether the demo brokerage/branches/agreements exist, and which spec items are
 * still missing. Everything is counted from rows flagged is_demo=true, so real data never appears here.
 */
final class DemoCoverageReport
{
    public const TABLES = ['quotes', 'proposals', 'underwriting_cases', 'payment_intents', 'policies', 'policy_issuance_requests', 'claims', 'commission_accruals'];

    public function build(): array
    {
        $states = [];
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'is_demo') || ! Schema::hasColumn($table, 'status')) {
                continue;
            }
            $states[$table] = DB::table($table)->where('is_demo', true)->selectRaw('status, count(*) as n')->groupBy('status')->orderBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        }

        $tenantId = DB::table('tenants')->where('slug', DemoInstitutionalSeeder::TENANT_SLUG)->value('id');
        $partnerId = DB::table('partners')->where('canonical_id', DemoInstitutionalSeeder::CANONICAL_ID)->value('id');
        $institutional = [
            'demo_brokerage' => $partnerId !== null,
            'demo_branches' => $tenantId ? DB::table('tenant_branches')->where('tenant_id', $tenantId)->where('is_demo', true)->count() : 0,
            'demo_agreements' => $partnerId ? DB::table('carrier_broker_agreements')->where('partner_id', $partnerId)->where('is_demo', true)->count() : 0,
        ];

        $gaps = [];
        if (! $institutional['demo_brokerage']) {
            $gaps[] = 'DEMO_BROKERAGE_MISSING';
        }
        if ($institutional['demo_branches'] < count(DemoInstitutionalSeeder::BRANCHES)) {
            $gaps[] = 'DEMO_BRANCHES_INCOMPLETE';
        }
        if ($institutional['demo_agreements'] === 0) {
            $gaps[] = 'DEMO_AGREEMENTS_MISSING';
        }
        foreach ($states as $table => $byStatus) {
            if ($byStatus === []) {
                $gaps[] = strtoupper($table).'_NO_DEMO_RECORDS';
            }
        }
        if (! DB::table('tenant_customers')->where('customer_number', 'CUS-DEMO-0001')->exists()) {
            $gaps[] = 'END_TO_END_CHAIN_CUS_DEMO_0001_MISSING';
        }

        return ['environment' => app(DemoEnvironment::class)->toArray(), 'institutional' => $institutional, 'states' => $states, 'gaps' => $gaps,
            'labels' => ['banner' => DemoEnvironment::BANNER_TEXT, 'watermark' => DemoEnvironment::DOCUMENT_WATERMARK, 'tariff' => DemoEnvironment::TARIFF_LABEL, 'qr_status' => DemoEnvironment::QR_VERIFICATION_STATUS]];
    }
}
