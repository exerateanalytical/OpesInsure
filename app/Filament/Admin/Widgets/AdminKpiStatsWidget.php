<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Application\Reporting\Dashboards\DashboardRegistry;
use App\Application\WebExperiences\Money;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;

/**
 * Admin home KPI tiles: the governed "insurer" operations and "finance" control dashboards of DashboardRegistry for the
 * admin's tenant (policies, claims, reserve, premium collected, receivables, commissions, refunds) — live values, each
 * drilling down to its admin list when that resource exists.
 */
final class AdminKpiStatsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = -1;

    protected ?string $pollingInterval = null;

    protected function getColumns(): int|array|null
    {
        return ['default' => 1, 'sm' => 2, 'lg' => 4];
    }

    public static function canView(): bool
    {
        return auth()->user() instanceof User && rescue(fn () => app(TenantContext::class)->id(), null, false) !== null;
    }

    protected function getStats(): array
    {
        $tenantId = rescue(fn () => app(TenantContext::class)->id(), null, false);
        if ($tenantId === null) {
            return [];
        }
        $stats = [];
        foreach (['insurer', 'finance'] as $dashboard) {
            $formats = array_column(DashboardRegistry::get($dashboard)['tiles'], 'format', 'key');
            foreach (app(DashboardRegistry::class)->data($tenantId, $dashboard)['tiles'] as $t) {
                if (isset($stats[$t['key']])) {
                    continue;
                }
                $value = ($formats[$t['key']] ?? null) === 'money_total' ? Money::format((int) $t['value'], 'XAF') : (string) (int) $t['value'];
                $label = Lang::has('web_experience.metrics.'.$t['key']) ? __('web_experience.metrics.'.$t['key']) : __('dashboards.metrics.'.$t['key']);
                $stat = Stat::make($label, $value)->color($t['tone'])->extraAttributes(['data-metric' => $t['key']]);
                $route = $t['drilldown'] ? "filament.admin.resources.{$t['drilldown']}.index" : null;
                $stats[$t['key']] = $route && Route::has($route) ? $stat->url(route($route)) : $stat;
            }
        }

        return array_values($stats);
    }
}
