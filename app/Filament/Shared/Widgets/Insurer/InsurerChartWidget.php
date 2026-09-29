<?php

declare(strict_types=1);

namespace App\Filament\Shared\Widgets\Insurer;

use App\Application\WebExperiences\InsurerDashboards;
use App\Filament\Shared\Pages\Insurer\InsurerDashboardPage;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** One chart of an /insurer dashboard (CAR-002 … CAR-007); data from InsurerDashboards (own carrier only). */
final class InsurerChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    /** production_count | production_premium | claims_trend | claims_status | portfolio_status | portfolio_line | brokers_top | products_premium */
    public string $chart = 'production_count';

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 1];

    protected ?string $maxHeight = '280px';

    /** Chart => the dashboard whose permission it needs. */
    public const DASHBOARD = ['production_count' => 'production', 'production_premium' => 'production', 'claims_trend' => 'claims', 'claims_status' => 'claims',
        'portfolio_status' => 'portfolio', 'portfolio_line' => 'portfolio', 'brokers_top' => 'brokers', 'products_premium' => 'products'];

    /** Ranked bar charts (many labels) take the full row. */
    public function getColumnSpan(): int|string|array
    {
        return in_array($this->chart, ['brokers_top', 'products_premium'], true) ? 'full' : $this->columnSpan;
    }

    public static function canView(): bool
    {
        return InsurerDashboardPage::mayView('operations') || InsurerDashboardPage::mayView('claims');
    }

    public function getHeading(): string
    {
        return __('insurer_screens.charts.'.$this->chart);
    }

    protected function getType(): string
    {
        return match ($this->chart) {
            'claims_status', 'portfolio_status' => 'doughnut',
            'production_premium', 'claims_trend' => 'line',
            default => 'bar',
        };
    }

    /** @return array{labels: list<string>, datasets: list<array<string, mixed>>} */
    public function chartData(): array
    {
        if (! InsurerDashboardPage::mayView(self::DASHBOARD[$this->chart] ?? 'operations')) {
            return ['labels' => [], 'datasets' => []];
        }
        $svc = app(InsurerDashboards::class);
        $label = fn (string $k) => __('insurer_screens.series.'.$k);
        $pairs = fn (array $m, string $series) => ['labels' => array_map('strval', array_keys($m)), 'datasets' => [['label' => $label($series), 'data' => array_values($m)]]];

        return match ($this->chart) {
            'production_count' => ($s = $svc->productionSeries()) ? ['labels' => $s['labels'], 'datasets' => [['label' => $label('policies_issued'), 'data' => $s['count']]]] : [],
            'production_premium' => ($s = $svc->productionSeries()) ? ['labels' => $s['labels'], 'datasets' => [['label' => $label('written_premium').' ('.$s['currency'].')', 'data' => $s['premium']]]] : [],
            'claims_trend' => ($s = $svc->claimsSeries()) ? ['labels' => $s['labels'], 'datasets' => [
                ['label' => $label('claims_reported'), 'data' => $s['reported']], ['label' => $label('claims_closed'), 'data' => $s['closed']]]] : [],
            'claims_status' => $pairs($svc->claimsByStatus(), 'claims'),
            'portfolio_status' => $pairs($svc->policiesByStatus(), 'policies'),
            'portfolio_line' => $pairs($svc->activeByLine(), 'active_policies'),
            'brokers_top' => $pairs(collect($svc->intermediaries())->take(10)->mapWithKeys(fn ($r) => [(string) $r['name'] => (int) round(((int) $r['premium_minor']) / 100)])->all(), 'written_premium'),
            'products_premium' => $pairs(collect($svc->productPerformance($this->pageFilters['period'] ?? null))->take(10)
                ->mapWithKeys(fn ($r) => [$r['product'].' '.$r['version'] => (int) round($r['premium_minor'] / 100)])->all(), 'written_premium'),
            default => ['labels' => [], 'datasets' => []],
        };
    }

    protected function getData(): array
    {
        return $this->chartData();
    }
}
