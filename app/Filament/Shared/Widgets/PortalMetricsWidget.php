<?php

declare(strict_types=1);

namespace App\Filament\Shared\Widgets;

use App\Application\WebExperiences\PortalDashboardMetrics;
use App\Domain\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Route;

/** Insurer / broker dashboard KPI tiles from PortalDashboardMetrics (live, tenant-scoped), each drilling down to its list screen. */
final class PortalMetricsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '120s';

    protected function getStats(): array
    {
        $tenantId = app(TenantContext::class)->id();
        $panel = Filament::getCurrentOrDefaultPanel()->getId();
        if ($tenantId === null) {
            return [];
        }

        return array_map(function (array $m) use ($panel): Stat {
            $stat = Stat::make($m['label'], (string) $m['value'])->color($m['tone'])->extraAttributes(['data-metric' => $m['key']]);
            $route = $m['drilldown'] ? "filament.{$panel}.resources.{$m['drilldown']}.index" : null;

            return $route && Route::has($route) ? $stat->url(route($route)) : $stat;
        }, app(PortalDashboardMetrics::class)->for($panel, $tenantId));
    }
}
