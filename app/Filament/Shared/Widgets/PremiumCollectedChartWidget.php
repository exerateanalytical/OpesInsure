<?php

declare(strict_types=1);

namespace App\Filament\Shared\Widgets;

use App\Application\Reporting\Kpi\KpiCatalogueService;
use App\Application\Reporting\Kpi\KpiEvaluator;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/**
 * Premium collected per month over the last six months, one series per currency: the governed KPI payments.collected
 * (succeeded payment intents of the tenant) evaluated month by month — the same figure as the finance dashboard tile.
 */
final class PremiumCollectedChartWidget extends ChartWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 1];

    protected ?string $maxHeight = '260px';

    // The panel tenant middleware is persistent on Livewire round-trips (ScopesPanelTenant), so polling keeps the tenant.
    protected ?string $pollingInterval = '120s';

    public static function canView(): bool
    {
        $u = auth()->user();

        return $u instanceof User && rescue(fn () => app(TenantContext::class)->id(), null, false) !== null
            && (bool) rescue(fn () => $u->hasPermission('finance.reports.view') || $u->hasPermission('reporting.dashboards.view'), false, false);
    }

    public function getHeading(): string
    {
        return __('dashboards.widgets.premium_collected');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array{labels: list<string>, series: array<string, list<int>>} */
    public function series(): array
    {
        $tenant = app(TenantContext::class)->id();
        $kpi = app(KpiCatalogueService::class)->resolve($tenant, 'payments.collected');
        $labels = [];
        $series = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = CarbonImmutable::now()->startOfMonth()->subMonths($i);
            $labels[] = $m->translatedFormat('M Y');
            $v = app(KpiEvaluator::class)->value($tenant, $kpi, ['from' => $m->toDateString(), 'to' => $m->endOfMonth()->toDateString()]);
            foreach ($v['by_currency'] ?? [] as $ccy => $amount) {
                $series[$ccy] ??= array_fill(0, 6, 0);
                $series[$ccy][5 - $i] = (int) $amount;
            }
        }

        return ['labels' => $labels, 'series' => $series];
    }

    protected function getData(): array
    {
        $s = $this->series();

        return ['labels' => $s['labels'], 'datasets' => array_map(fn (string $ccy, array $data) => ['label' => $ccy, 'data' => $data], array_keys($s['series']), array_values($s['series']))];
    }
}
