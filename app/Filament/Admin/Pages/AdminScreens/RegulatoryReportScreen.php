<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Application\Reporting\Catalogue\ReportCatalogue;
use App\Application\Reporting\Dashboards\DashboardRegistry;
use App\Application\Reporting\Kpi\KpiCatalogueService;
use App\Application\Reporting\Kpi\KpiEvaluator;
use App\Application\Reporting\Kpi\KpiQueryRegistry;
use App\Filament\Shared\Columns;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * REG-001/002/004/005/006 per-report screens — one screen per report family of the report catalogue (GET
 * reporting/reports/{code}, reporting.reports.view, ReportCatalogue::show). Each governed KPI source is evaluated
 * for this organisation (KpiEvaluator, the same values as GET reporting/kpis/{code}/value), a dashboard source shows
 * its tiles, and finance reports link to the reports screen where they are run and exported.
 */
abstract class RegulatoryReportScreen extends AdminScreenPage
{
    protected const CODE = '';

    protected static array $permissions = ['reporting.reports.view'];

    protected static string $group = 'Trust & compliance';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    /** @return array<string, array<string, mixed>> source rows with their current value */
    public function sources(): array
    {
        if ($this->cache !== null || $this->tenantId === null) {
            return $this->cache ?? [];
        }
        $report = app(ReportCatalogue::class)->show($this->tenantId, static::CODE);
        $rows = [];
        foreach ($report['sources'] as $i => $s) {
            $key = $s['kind'].':'.$s['ref'];
            $base = ['__key' => $key, 'id' => $key, 'kind' => $s['kind'], 'title' => $s['title'], 'status' => $s['status'], 'value' => '—', 'unit' => null, 'run' => $s['run'] ?? null];
            if ($s['kind'] === ReportCatalogue::KIND_KPI) {
                $base = array_merge($base, $this->kpiValue($s['ref']));
                $rows[$key] = $base;
            } elseif ($s['kind'] === ReportCatalogue::KIND_DASHBOARD) {
                $rows[$key] = $base;
                foreach (rescue(fn () => app(DashboardRegistry::class)->data($this->tenantId, $s['ref'])['tiles'], [], false) as $tile) {
                    $tk = $key.':'.$tile['key'];
                    $rows[$tk] = ['__key' => $tk, 'id' => $tk, 'kind' => ReportCatalogue::KIND_KPI, 'title' => '↳ '.$tile['kpi']['name'], 'status' => $tile['kpi']['status'],
                        'value' => $this->format($tile['value'], $tile['by_currency'], $tile['kpi']['unit']), 'unit' => $tile['kpi']['unit'], 'run' => null];
                }
            } else {
                $rows[$key] = $base;
            }
        }

        return $this->cache = $rows;
    }

    /** @return array{value:string, unit:?string} */
    private function kpiValue(string $code): array
    {
        return rescue(function () use ($code) {
            $kpi = app(KpiCatalogueService::class)->resolve($this->tenantId, $code);
            $v = app(KpiEvaluator::class)->value($this->tenantId, $kpi);

            return ['value' => $this->format($v['value'], $v['by_currency'], $kpi['unit'] ?? null), 'unit' => $kpi['unit'] ?? null];
        }, ['value' => '—', 'unit' => null], false);
    }

    private function format(int|float $value, ?array $byCurrency, ?string $unit): string
    {
        if ($unit === KpiQueryRegistry::UNIT_MONEY) {
            return collect($byCurrency ?: ['XAF' => $value])->map(fn ($minor, $ccy) => self::money((int) $minor, (string) $ccy))->implode(' · ') ?: self::money(0);
        }

        return number_format((float) $value, 0, ',', ' ');
    }

    public function kpis(): array
    {
        return collect($this->sources())->filter(fn ($r) => $r['kind'] === ReportCatalogue::KIND_KPI && $r['value'] !== '—')->take(4)
            ->map(fn ($r) => ['label' => ltrim($r['title'], '↳ '), 'value' => $r['value'], 'tone' => null, 'hint' => null])->values()->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->sources())
            ->columns([
                TextColumn::make('title')->label(self::col('source')),
                TextColumn::make('kind')->label(self::col('kind'))->formatStateUsing(fn ($state) => __('admin_screens.source_kinds.'.$state))->badge(),
                TextColumn::make('value')->label(self::col('current_value'))->alignEnd(),
                Columns::status('status', self::col('status')),
                TextColumn::make('open')->label(self::col('open_in'))
                    ->state(fn (array $record) => $record['kind'] === ReportCatalogue::KIND_FINANCE_REPORT ? __('admin_screens.open_reports') : '—')
                    ->url(fn (array $record) => $record['kind'] === ReportCatalogue::KIND_FINANCE_REPORT ? rescue(fn () => \App\Filament\Shared\Pages\ReportsPage::getUrl(), null, false) : null),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
