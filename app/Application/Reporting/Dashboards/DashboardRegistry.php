<?php

declare(strict_types=1);

namespace App\Application\Reporting\Dashboards;

use App\Application\Reporting\Kpi\KpiCatalogueService;
use App\Application\Reporting\Kpi\KpiEvaluator;
use App\Application\Reporting\Kpi\KpiQueryRegistry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Agent B2 — REQ-RPT-004 dashboard definition registry + data API. A dashboard is a list of tiles; every tile
 * binds to a governed KPI code (KpiCatalogueService) — never to its own SQL — and drills down to the KPI's
 * record set (KpiEvaluator::drill). PortalDashboardMetrics (insurer/broker web dashboards) reads from here.
 *
 * Tile shape: key (stable, used by existing screens), kpi (catalogue code), tone, drilldown (list screen or null),
 * period (optional {last_days}), format ('count'|'money_total').
 */
final class DashboardRegistry
{
    /** @return array<string, array{title:string, audience:string, tiles:list<array<string,mixed>>}> */
    public static function definitions(): array
    {
        return [
            'insurer' => ['title' => 'Insurer operations', 'audience' => 'insurer', 'tiles' => [
                ['key' => 'active_policies', 'kpi' => 'policies.active', 'tone' => 'success', 'drilldown' => 'policies'],
                ['key' => 'pending_issuance', 'kpi' => 'policies.pending_issuance', 'tone' => 'warning', 'drilldown' => 'policies'],
                ['key' => 'open_claims', 'kpi' => 'claims.open', 'tone' => 'info', 'drilldown' => 'claims'],
                ['key' => 'outstanding_reserve', 'kpi' => 'claims.outstanding_reserve', 'tone' => 'info', 'drilldown' => 'claims', 'format' => 'money_total'],
                ['key' => 'underwriting_queue', 'kpi' => 'underwriting.queue', 'tone' => 'warning', 'drilldown' => null],
                ['key' => 'failed_claim_payments', 'kpi' => 'claims.failed_payments', 'tone' => 'danger', 'drilldown' => 'claims'],
            ]],
            'broker' => ['title' => 'Broker sales', 'audience' => 'broker', 'tiles' => [
                ['key' => 'quotes_30d', 'kpi' => 'quotes.created', 'tone' => 'info', 'drilldown' => 'quotes', 'period' => ['last_days' => 30]],
                ['key' => 'proposals_open', 'kpi' => 'proposals.open', 'tone' => 'warning', 'drilldown' => null],
                ['key' => 'active_policies', 'kpi' => 'policies.active', 'tone' => 'success', 'drilldown' => 'policies'],
                ['key' => 'renewals_due_30d', 'kpi' => 'policies.expiring_30d', 'tone' => 'warning', 'drilldown' => 'policies'],
                ['key' => 'payments_pending', 'kpi' => 'payments.pending', 'tone' => 'warning', 'drilldown' => null],
                ['key' => 'open_claims', 'kpi' => 'claims.not_closed', 'tone' => 'info', 'drilldown' => 'claims'],
            ]],
            'finance' => ['title' => 'Finance control', 'audience' => 'finance', 'tiles' => [
                ['key' => 'premium_collected_30d', 'kpi' => 'payments.collected', 'tone' => 'success', 'drilldown' => 'payments', 'period' => ['last_days' => 30], 'format' => 'money_total'],
                ['key' => 'receivables_outstanding', 'kpi' => 'receivables.outstanding', 'tone' => 'info', 'drilldown' => 'financial-obligations', 'format' => 'money_total'],
                ['key' => 'receivables_overdue', 'kpi' => 'receivables.overdue', 'tone' => 'danger', 'drilldown' => 'financial-obligations', 'format' => 'money_total'],
                ['key' => 'commissions_pending', 'kpi' => 'commissions.pending', 'tone' => 'info', 'drilldown' => 'commission-accruals', 'format' => 'money_total'],
                ['key' => 'refunds_open', 'kpi' => 'refunds.open', 'tone' => 'warning', 'drilldown' => 'refunds'],
            ]],
            'compliance' => ['title' => 'Compliance & regulatory (REG-001)', 'audience' => 'compliance', 'tiles' => [
                ['key' => 'kyc_pending_review', 'kpi' => 'kyc.pending_review', 'tone' => 'warning', 'drilldown' => 'kyc-submissions'],
                ['key' => 'regulatory_runs_open', 'kpi' => 'regulatory.runs_open', 'tone' => 'warning', 'drilldown' => 'regulatory-report-runs'],
                ['key' => 'renewals_due', 'kpi' => 'renewals.due', 'tone' => 'info', 'drilldown' => 'renewals'],
            ]],
        ];
    }

    public function __construct(private KpiCatalogueService $kpis, private KpiEvaluator $evaluator) {}

    /** @return list<array{code:string, title:string, audience:string, tiles:int}> */
    public function index(): array
    {
        $out = [];
        foreach (self::definitions() as $code => $d) {
            $out[] = ['code' => $code, 'title' => $d['title'], 'audience' => $d['audience'], 'tiles' => count($d['tiles'])];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function get(string $code): array
    {
        return self::definitions()[$code] ?? throw new NotFoundHttpException("Unknown dashboard {$code}.");
    }

    /**
     * Dashboard data: each tile with its governed KPI definition (code, version, status) and live value.
     *
     * @return array{code:string, title:string, generated_at:string, tiles:list<array<string,mixed>>}
     */
    public function data(string $tenantId, string $code): array
    {
        $d = self::get($code);
        $tiles = [];
        foreach ($d['tiles'] as $tile) {
            $kpi = $this->kpis->resolve($tenantId, $tile['kpi']);
            $v = $this->evaluator->value($tenantId, $kpi, $tile['period'] ?? []);
            $tiles[] = [
                'key' => $tile['key'], 'kpi' => ['code' => $kpi['code'], 'version' => $kpi['version'], 'status' => $kpi['status'], 'name' => $kpi['name'], 'unit' => $kpi['unit']],
                'value' => $v['value'], 'by_currency' => $v['by_currency'], 'tone' => $tile['tone'], 'drilldown' => $tile['drilldown'],
                'period' => $tile['period'] ?? null,
                'attention' => ! empty(KpiQueryRegistry::get($kpi['query_key'])['attention_when_positive']) && $v['value'] > 0,
            ];
        }

        return ['code' => $code, 'title' => $d['title'], 'generated_at' => now()->toIso8601String(), 'tiles' => $tiles];
    }

    /** Drill-down of one tile: the governed KPI's record set (tile period applied). */
    public function drill(string $tenantId, string $code, string $tileKey, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $tile = collect(self::get($code)['tiles'])->firstWhere('key', $tileKey) ?? throw new NotFoundHttpException("Unknown tile {$tileKey}.");
        $kpi = $this->kpis->resolve($tenantId, $tile['kpi']);

        return ['dashboard' => $code, 'tile' => $tileKey, 'kpi' => ['code' => $kpi['code'], 'version' => $kpi['version']]]
            + $this->evaluator->drill($tenantId, $kpi, $tile['period'] ?? [], $filters, $page, $perPage);
    }
}
