<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Policies\RenewalService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Policy;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily renewal sweep: runs RenewalService::sweep() for every tenant holding ACTIVE / EXPIRING policies or open
 * renewal cases, with that tenant set as the tenant context (opens cases + work items, advances windows, lapses
 * overdue cases). Idempotent — sweep() uses firstOrCreate / insertOrIgnore.
 */
final class SweepRenewals extends Command
{
    protected $signature = 'renewals:sweep {--days=90 : look-ahead window in days for policies ending soon}';

    protected $description = 'Open, advance and lapse renewal cases for every tenant.';

    public function handle(RenewalService $renewals, TenantContext $context): int
    {
        $tenantIds = Policy::query()->whereIn('status', ['ACTIVE', 'EXPIRING'])->distinct()->pluck('tenant_id')
            ->merge(DB::table('renewal_cases')->whereIn('status', ['DUE', 'CONTACTED'])->distinct()->pluck('tenant_id'))
            ->map(fn ($id) => (string) $id)->unique()->values();
        $totals = ['cases' => 0, 'work_items_created' => 0, 'windows_reached' => 0, 'lapsed' => 0];
        foreach (Tenant::whereIn('id', $tenantIds)->get() as $tenant) {
            $context->set((string) $tenant->id);
            try {
                foreach ($renewals->sweep($tenant, (int) $this->option('days'), null) as $k => $v) {
                    $totals[$k] = ($totals[$k] ?? 0) + $v;
                }
            } finally {
                $context->clear();
            }
        }
        $this->info("Tenants swept: {$tenantIds->count()}. Cases: {$totals['cases']}. Work items created: {$totals['work_items_created']}. Windows reached: {$totals['windows_reached']}. Lapsed: {$totals['lapsed']}.");

        return self::SUCCESS;
    }
}
