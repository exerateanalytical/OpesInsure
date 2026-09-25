<?php

declare(strict_types=1);

namespace App\Application\Reporting\Catalogue;

use App\Application\Finance\Reports\FinanceReportRegistry;
use App\Application\Reporting\Dashboards\DashboardRegistry;
use App\Application\Reporting\Kpi\KpiQueryRegistry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Agent B2 — REQ-RPT-005 one report catalogue. Unifies the reporting families REG-001..008 (ESR) with the finance
 * report registry FR-01..20 (App\Application\Finance\Reports\FinanceReportRegistry — referenced, not duplicated).
 *
 * Every entry points at an existing producer: a dashboard (DashboardRegistry), a governed KPI drill-down
 * (KpiQueryRegistry), a finance report (FR-xx), or an existing API endpoint. Regulatory return formats are NOT
 * defined here: REG-008 lists the tenant's approved regulatory_report_definitions (formats are configured data).
 */
final class ReportCatalogue
{
    public const KIND_DASHBOARD = 'DASHBOARD';

    public const KIND_KPI = 'KPI';

    public const KIND_FINANCE_REPORT = 'FINANCE_REPORT';

    public const KIND_ENDPOINT = 'ENDPOINT';

    /** @return array<string, array{title:string, sources:list<array<string,string>>}> */
    public static function families(): array
    {
        return [
            'REG-001' => ['title' => 'Regulatory Reporting Dashboard', 'sources' => [['kind' => self::KIND_DASHBOARD, 'ref' => 'compliance']]],
            'REG-002' => ['title' => 'Premium Production Reports', 'sources' => [
                ['kind' => self::KIND_KPI, 'ref' => 'premium.written'], ['kind' => self::KIND_KPI, 'ref' => 'policies.issued'],
                ['kind' => self::KIND_FINANCE_REPORT, 'ref' => 'FR-06'], ['kind' => self::KIND_ENDPOINT, 'ref' => 'GET /api/v1/finance/technical/reports/premiums']]],
            'REG-003' => ['title' => 'Policy Portfolio Reports', 'sources' => [
                ['kind' => self::KIND_ENDPOINT, 'ref' => 'GET /api/v1/reports/insurance-portfolio'], ['kind' => self::KIND_ENDPOINT, 'ref' => 'GET /api/v1/reports/renewals'],
                ['kind' => self::KIND_KPI, 'ref' => 'policies.active'], ['kind' => self::KIND_KPI, 'ref' => 'policies.expiring_30d']]],
            'REG-004' => ['title' => 'Claims Reports', 'sources' => [
                ['kind' => self::KIND_KPI, 'ref' => 'claims.reported'], ['kind' => self::KIND_KPI, 'ref' => 'claims.open'], ['kind' => self::KIND_KPI, 'ref' => 'claims.outstanding_reserve'],
                ['kind' => self::KIND_FINANCE_REPORT, 'ref' => 'FR-18'], ['kind' => self::KIND_ENDPOINT, 'ref' => 'GET /api/v1/finance/technical/reports/claims']]],
            'REG-005' => ['title' => 'Intermediary Reports', 'sources' => [
                ['kind' => self::KIND_FINANCE_REPORT, 'ref' => 'FR-17'], ['kind' => self::KIND_DASHBOARD, 'ref' => 'broker']]],
            'REG-006' => ['title' => 'Commission Reports', 'sources' => [
                ['kind' => self::KIND_FINANCE_REPORT, 'ref' => 'FR-15'], ['kind' => self::KIND_KPI, 'ref' => 'commissions.pending']]],
            'REG-007' => ['title' => 'Compliance / Audit Reports', 'sources' => [
                ['kind' => self::KIND_KPI, 'ref' => 'kyc.pending_review'], ['kind' => self::KIND_ENDPOINT, 'ref' => 'GET /api/v1/compliance/audit-log']]],
            'REG-008' => ['title' => 'Regulatory Report Generation & Export', 'sources' => [
                ['kind' => self::KIND_KPI, 'ref' => 'regulatory.runs_open'], ['kind' => self::KIND_ENDPOINT, 'ref' => 'POST /api/v1/trust/regulatory-reports/{definition}/runs']]],
        ];
    }

    public function __construct(private FinanceReportRegistry $finance) {}

    /**
     * @return list<array{code:string, family:string, title:string, kind:string, status:string, reason:?string, sources:list<array<string,mixed>>}>
     */
    public function catalogue(string $tenantId): array
    {
        $fr = collect($this->finance->catalogue())->keyBy('code');
        $out = [];
        foreach (self::families() as $code => $f) {
            $sources = array_map(fn (array $s) => $this->describe($s, $fr->all()), $f['sources']);
            $out[] = ['code' => $code, 'family' => 'REG', 'title' => $f['title'], 'kind' => 'FAMILY', 'status' => $this->familyStatus($sources), 'reason' => null, 'sources' => $sources];
        }
        foreach ($fr as $code => $r) {
            $families = array_keys(array_filter(self::families(), fn ($f) => in_array(['kind' => self::KIND_FINANCE_REPORT, 'ref' => $code], $f['sources'], true)));
            $out[] = ['code' => $code, 'family' => 'FIN', 'title' => $r['title'], 'kind' => self::KIND_FINANCE_REPORT, 'status' => $r['status'], 'reason' => $r['reason'],
                'sources' => [['kind' => self::KIND_FINANCE_REPORT, 'ref' => $code, 'screen' => $r['screen'], 'run' => 'GET /api/v1/finance/reports/'.$code, 'also_in' => $families]]];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function show(string $tenantId, string $code): array
    {
        return collect($this->catalogue($tenantId))->firstWhere('code', strtoupper($code)) ?? throw new NotFoundHttpException("Unknown report {$code}.");
    }

    /** @param array<string, array<string, mixed>> $fr */
    private function describe(array $s, array $fr): array
    {
        return match ($s['kind']) {
            self::KIND_DASHBOARD => $s + ['title' => DashboardRegistry::get($s['ref'])['title'], 'status' => FinanceReportRegistry::AVAILABLE, 'run' => 'GET /api/v1/reporting/dashboards/'.$s['ref']],
            self::KIND_KPI => $s + ['title' => KpiQueryRegistry::get($s['ref'])['label'], 'status' => FinanceReportRegistry::AVAILABLE, 'run' => 'GET /api/v1/reporting/kpis/'.$s['ref'].'/drill'],
            self::KIND_FINANCE_REPORT => $s + ['title' => $fr[$s['ref']]['title'], 'status' => $fr[$s['ref']]['status'], 'run' => 'GET /api/v1/finance/reports/'.$s['ref']],
            default => $s + ['title' => $s['ref'], 'status' => FinanceReportRegistry::AVAILABLE, 'run' => $s['ref']],
        };
    }

    private function familyStatus(array $sources): string
    {
        return collect($sources)->contains('status', FinanceReportRegistry::AVAILABLE) ? FinanceReportRegistry::AVAILABLE : FinanceReportRegistry::NOT_AVAILABLE;
    }
}
