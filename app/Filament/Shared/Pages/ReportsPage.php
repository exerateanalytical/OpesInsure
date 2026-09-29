<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Application\Finance\Reports\FinanceReportRegistry;
use App\Application\Reporting\Kpi\KpiCatalogueService;
use App\Application\Reporting\Kpi\KpiEvaluator;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnitEnum;

/**
 * Reports screen for the admin, insurer and broker panels (REQ-RPT-005 report catalogue, web side). It runs the SAME
 * producers as the API — no report logic of its own:
 *  - FR-xx finance reports: FinanceReportRegistry::run / ::toCsv (GET /api/v1/finance/reports/{code}), permission finance.reports.view;
 *  - governed KPI drill-downs: KpiEvaluator::drill (GET /api/v1/reporting/kpis/{code}/drill), permission reporting.kpis.view;
 *  - insurance portfolio / renewals: InsuranceReportController (GET /api/v1/reports/insurance-portfolio|renewals), permission reports.insurance.read.
 * Filters: period (from / to), currency, as-of date. CSV export = exactly the rows on screen.
 *
 * The tenant is captured at mount (panel tenant middleware is not persistent on Livewire round-trips) and locked.
 */
final class ReportsPage extends Page
{
    protected string $view = 'filament.shared.pages.reports';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-file-chart-column';

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'reports';

    private const PERMISSIONS = ['FR' => 'finance.reports.view', 'KPI' => 'reporting.kpis.view', 'INS' => 'reports.insurance.read'];

    #[Locked]
    public ?string $tenantId = null;

    public ?string $report = null;

    public ?string $from = null;

    public ?string $to = null;

    public ?string $currency = null;

    public ?string $asOf = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u instanceof User && collect(self::PERMISSIONS)->contains(fn ($p) => (bool) rescue(fn () => $u->hasPermission($p), false, false));
    }

    public static function getNavigationLabel(): string
    {
        return __('dashboards.reports.title');
    }

    public function getTitle(): string
    {
        return __('dashboards.reports.title');
    }

    public function mount(): void
    {
        $this->tenantId = rescue(fn () => app(TenantContext::class)->id(), null, false);
        $this->from = now()->startOfYear()->toDateString();
        $this->to = now()->toDateString();
        $this->report = array_key_first($this->options());
    }

    /** The caller's carrier inside /insurer (null elsewhere or at tenant-wide scope). */
    private function carrier(): ?string
    {
        return rescue(fn () => \App\Application\WebExperiences\PortalScope::carrierId(), null, false);
    }

    private function may(string $family): bool
    {
        return (bool) rescue(fn () => auth()->user()->hasPermission(self::PERMISSIONS[$family]), false, false);
    }

    /** Selectable reports grouped by family, filtered by the user's permissions. @return array<string, string> key => label */
    public function options(): array
    {
        $o = [];
        if ($this->may('INS')) {
            $o['INS:portfolio'] = __('dashboards.reports.insurance_portfolio');
            $o['INS:renewals'] = __('dashboards.reports.renewals');
        }
        // CAR-034 (Q7): the finance registry reports are tenant-wide, not per carrier — not offered to a carrier-scoped
        // /insurer user (their figures would include other carriers' business).
        if ($this->may('FR') && $this->carrier() === null) {
            foreach (app(FinanceReportRegistry::class)->catalogue() as $r) {
                $o['FR:'.$r['code']] = $r['code'].' — '.$r['title'].($r['status'] === FinanceReportRegistry::AVAILABLE ? '' : ' ('.__('dashboards.reports.not_available').')');
            }
        }
        if ($this->may('KPI') && $this->tenantId) {
            foreach (rescue(fn () => app(KpiCatalogueService::class)->catalogue($this->tenantId), [], false) as $k) {
                $k = (array) $k;
                $o['KPI:'.$k['code']] = __('dashboards.reports.kpi').' — '.($k['name'] ?? $k['label'] ?? $k['code']);
            }
        }

        return $o;
    }

    /** @return array{title: string, status: string, reason: ?string, columns: list<string>, rows: list<array<string, mixed>>, truncated: bool} */
    public function result(): array
    {
        $empty = ['title' => (string) ($this->options()[$this->report] ?? ''), 'status' => FinanceReportRegistry::AVAILABLE, 'reason' => null, 'columns' => [], 'rows' => [], 'truncated' => false];
        if ($this->tenantId === null || $this->report === null || ! array_key_exists($this->report, $this->options())) {
            return $empty;
        }
        $v = Validator::make(['from' => $this->from, 'to' => $this->to, 'as_of' => $this->asOf, 'currency' => $this->currency],
            ['from' => 'nullable|date', 'to' => 'nullable|date', 'as_of' => 'nullable|date', 'currency' => 'nullable|string|size:3']);
        if ($v->fails()) {
            return ['status' => 'INVALID', 'reason' => $v->errors()->first()] + $empty;
        }
        [$family, $code] = explode(':', $this->report, 2);
        app(TenantContext::class)->set($this->tenantId);
        try {
            return match ($family) {
                'FR' => $this->finance($code) + $empty,
                'KPI' => $this->kpi($code) + $empty,
                'INS' => $this->insurance($code) + $empty,
            };
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'ERROR', 'reason' => __('dashboards.states.error')] + $empty;
        }
    }

    private function finance(string $code): array
    {
        $r = app(FinanceReportRegistry::class)->run($this->tenantId, $code, array_filter(['from' => $this->from, 'to' => $this->to, 'as_of' => $this->asOf, 'currency' => $this->currency]));

        return array_intersect_key($r, array_flip(['status', 'reason', 'columns', 'rows', 'truncated']));
    }

    private function kpi(string $code): array
    {
        $kpi = app(KpiCatalogueService::class)->resolve($this->tenantId, $code);
        $filters = $this->currency && isset(\App\Application\Reporting\Kpi\KpiQueryRegistry::get($kpi['query_key'])['filters']['currency']) ? ['currency' => strtoupper($this->currency)] : [];
        $d = app(KpiEvaluator::class)->drill($this->tenantId, $kpi, array_filter(['from' => $this->from, 'to' => $this->to]), $filters, 1, KpiEvaluator::MAX_PAGE);

        return ['columns' => $d['columns'], 'rows' => array_map(fn ($r) => (array) $r, $d['rows']), 'truncated' => $d['total'] > count($d['rows'])];
    }

    private function insurance(string $code): array
    {
        $c = app(\App\Interfaces\Http\Controllers\Api\V1\Reporting\InsuranceReportController::class);
        // /insurer: the API's own carrier_id filter, set to the caller's carrier (CAR-034).
        $carrier = array_filter(['carrier_id' => $this->carrier()]);
        if ($code === 'portfolio') {
            $data = json_decode((string) $c->portfolio(Request::create('/', 'GET', array_filter(['from' => $this->from, 'to' => $this->to]) + $carrier))->getContent(), true)['data'];
            $row = array_diff_key($data, ['period' => 1]);

            return ['columns' => array_keys($row), 'rows' => [$row]];
        }
        $rows = array_map(fn ($r) => (array) $r, json_decode((string) $c->renewals(Request::create('/', 'GET', $carrier))->getContent(), true)['data']);

        return ['columns' => $rows ? array_keys($rows[0]) : ['status', 'count'], 'rows' => $rows];
    }

    public function exportCsv(): ?StreamedResponse
    {
        $r = $this->result();
        if (! in_array($r['status'], [FinanceReportRegistry::AVAILABLE, FinanceReportRegistry::NOT_AVAILABLE], true)) {
            return null;
        }
        $csv = FinanceReportRegistry::toCsv(['code' => $this->report, 'status' => $r['status'], 'reason' => $r['reason'], 'columns' => $r['columns'], 'rows' => $r['rows']]);
        app(\App\Application\Audit\AuditWriter::class)->record('report.exported', 'report', null, ['report' => $this->report, 'rows' => count($r['rows']),
            'filters' => array_filter(['from' => $this->from, 'to' => $this->to, 'as_of' => $this->asOf, 'currency' => $this->currency])]);
        $name = strtolower(str_replace([':', ' '], '-', (string) $this->report)).'-'.now()->format('Ymd').'.csv';

        return response()->streamDownload(fn () => print ($csv), $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
