<?php

declare(strict_types=1);

namespace App\Application\Finance\Subledger\Http;

use App\Application\Finance\Reports\FinanceReportRegistry;
use App\Application\Finance\Subledger\AgingService;
use App\Application\Finance\Subledger\CounterpartyAccountService;
use App\Application\Finance\Subledger\PremiumRemittanceService;
use App\Application\Finance\Subledger\StatementDocumentService;
use App\Application\Finance\Subledger\SubledgerCatalogue;
use App\Application\Finance\Subledger\SubledgerQuery;
use App\Application\Finance\Subledger\SubledgerReports;
use App\Application\Finance\Subledger\SubledgerViews;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Agent F1 — finance counterparty accounts & commission sub-ledger API (owner spec v1). Every read is tenant-scoped. */
final class SubledgerController
{
    public function __construct(private TenantContext $tenant, private SubledgerQuery $query) {}

    // --- counterparty accounts ---
    public function accounts(Request $r): JsonResponse
    {
        $rows = DB::table('finance_counterparty_accounts')->where('tenant_id', $this->tenant->id())
            ->when($r->query('counterparty_id'), fn ($q, $v) => $q->where('counterparty_id', $v))->when($r->query('relationship_type'), fn ($q, $v) => $q->where('relationship_type', $v))
            ->orderBy('account_code')->get();

        return response()->json(['data' => $rows]);
    }

    public function openAccount(Request $r, CounterpartyAccountService $svc): JsonResponse
    {
        $d = $r->validate(['relationship_type' => 'required|string|in:'.implode(',', array_keys(SubledgerCatalogue::RELATIONSHIPS)), 'account_type' => 'required|string|max:40',
            'counterparty_id' => 'required|uuid', 'currency' => 'required|string|size:3', 'branch_id' => 'nullable|uuid', 'gl_account_code' => 'nullable|string|max:20']);

        return response()->json(['data' => $svc->open($this->tenant->id(), $d, $r->user()->id)], 201);
    }

    public function approveAccount(string $account, Request $r, CounterpartyAccountService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->approve($this->tenant->id(), $account, $r->user()->id)]);
    }

    public function accountStatus(string $account, Request $r, CounterpartyAccountService $svc): JsonResponse
    {
        $d = $r->validate(['status' => 'required|in:ACTIVE,SUSPENDED,CLOSED', 'reason' => 'required|string|max:500']);

        return response()->json(['data' => $svc->changeStatus($this->tenant->id(), $account, $d['status'], $d['reason'], $r->user()->id)]);
    }

    public function accountBalance(string $account, CounterpartyAccountService $svc): JsonResponse
    {
        abort_unless(DB::table('finance_counterparty_accounts')->where('tenant_id', $this->tenant->id())->where('id', $account)->exists(), 404);

        return response()->json(['data' => $svc->balance($this->tenant->id(), $account)]);
    }

    // --- sub-ledger entries / drill-down ---
    public function entries(Request $r): JsonResponse|Response
    {
        $f = $this->query->normalise($r->query());
        $result = $this->query->list($this->tenant->id(), $f);
        if ($r->query('format') === 'csv') {
            return $this->csv(['code' => 'SUBLEDGER_ENTRIES', 'status' => FinanceReportRegistry::AVAILABLE, 'columns' => $result['rows'] ? array_keys($result['rows'][0]) : [], 'rows' => $result['rows']], $f);
        }

        return response()->json(['data' => $result]);
    }

    public function balances(Request $r): JsonResponse
    {
        $f = $this->query->normalise($r->query());

        return response()->json(['data' => ['applied_filters' => $f, 'rows' => $this->query->balances($this->tenant->id(), $f, (string) $r->query('group_by', 'insurer'))]]);
    }

    public function drilldown(string $entry): JsonResponse
    {
        return response()->json(['data' => $this->query->drilldown($this->tenant->id(), $entry)]);
    }

    // --- mirrored views & dashboards ---
    public function brokerDashboard(Request $r, SubledgerViews $views): JsonResponse
    {
        return response()->json(['data' => $views->brokerDashboard($this->tenant->id(), $this->query->normalise($r->query()), $r->query('group_by'))]);
    }

    public function insurerDashboard(Request $r, SubledgerViews $views): JsonResponse
    {
        return response()->json(['data' => $views->insurerDashboard($this->tenant->id(), $this->query->normalise($r->query()), $r->query('group_by'))]);
    }

    public function brokerInsurer(string $insurer, Request $r, SubledgerViews $views): JsonResponse
    {
        return response()->json(['data' => $views->brokerInsurerAccount($this->tenant->id(), $insurer, $this->query->normalise($r->query()), strtoupper((string) $r->query('tab', 'OVERVIEW')))]);
    }

    public function insurerBroker(string $broker, Request $r, SubledgerViews $views): JsonResponse
    {
        return response()->json(['data' => $views->insurerBrokerAccount($this->tenant->id(), $broker, $this->query->normalise($r->query()), strtoupper((string) $r->query('tab', 'OVERVIEW')))]);
    }

    public function customerLedger(string $party, Request $r, SubledgerViews $views): JsonResponse
    {
        return response()->json(['data' => $views->customerLedger($this->tenant->id(), $party, strtoupper((string) $r->query('view', 'ACCOUNT_STATEMENT')), $this->query->normalise($r->query()))]);
    }

    public function agentLedger(string $agent, Request $r, SubledgerViews $views): JsonResponse
    {
        $f = $this->query->normalise($r->query()) + array_filter($r->only(['client', 'policy', 'product', 'insurer', 'date', 'status', 'branch', 'channel']));

        return response()->json(['data' => $views->agentLedger($this->tenant->id(), $agent, strtoupper((string) $r->query('view', 'PAYABLE_COMMISSION')), $f)]);
    }

    // --- remittances ---
    public function recordRemittance(Request $r, PremiumRemittanceService $svc): JsonResponse
    {
        $d = $r->validate(['insurer_id' => 'required|uuid', 'broker_id' => 'nullable|uuid', 'currency' => 'required|string|size:3', 'amount_minor' => 'required|integer|min:1',
            'remittance_date' => 'nullable|date', 'payment_reference' => 'nullable|string|max:120']);
        $d['idempotency_key'] = (string) ($r->header('Idempotency-Key') ?: $r->input('idempotency_key') ?: abort(422, 'Idempotency-Key header is required.'));

        return response()->json(['data' => $svc->record($this->tenant->id(), $d, $r->user()->id)], 201);
    }

    public function allocateRemittance(string $remittance, Request $r, PremiumRemittanceService $svc): JsonResponse
    {
        $d = $r->validate(['allocations' => 'required|array|min:1', 'allocations.*.financial_obligation_id' => 'required|uuid', 'allocations.*.amount_minor' => 'required|integer|min:1']);

        return response()->json(['data' => $svc->allocate($this->tenant->id(), $remittance, $d['allocations'], $r->user()->id)]);
    }

    public function holdRemittance(string $remittance, Request $r, PremiumRemittanceService $svc): JsonResponse
    {
        $d = $r->validate(['status' => 'required|in:DISPUTED,RECONCILIATION_HOLD,RELEASE', 'reason' => 'required|string|max:500']);

        return response()->json(['data' => $svc->hold($this->tenant->id(), $remittance, $d['status'], $d['reason'], $r->user()->id)]);
    }

    // --- aging ---
    public function aging(string $scope, Request $r, AgingService $aging): JsonResponse
    {
        $f = $this->query->normalise($r->query());

        return response()->json(['data' => $aging->age($this->tenant->id(), strtoupper($scope), $f, null, $r->query('basis') ? strtoupper((string) $r->query('basis')) : null) + ['applied_filters' => $f]]);
    }

    public function configureAging(Request $r, AgingService $aging): JsonResponse
    {
        $d = $r->validate(['scope' => 'required|string', 'basis' => 'required|string']);
        $aging->configure($this->tenant->id(), $d['scope'], $d['basis'], $r->user()->id);

        return response()->json(['data' => ['scope' => strtoupper($d['scope']), 'basis' => $aging->basis($this->tenant->id(), strtoupper($d['scope']))]]);
    }

    // --- reports & documents ---
    public function reports(SubledgerReports $reports): JsonResponse
    {
        return response()->json(['data' => $reports->catalogue()]);
    }

    public function report(string $report, Request $r, SubledgerReports $reports): JsonResponse|Response
    {
        $f = $this->query->normalise($r->query());
        $result = $reports->run($this->tenant->id(), strtoupper($report), $f);

        return $r->query('format') === 'csv' ? $this->csv($result, $f) : response()->json(['data' => $result]);
    }

    public function generateDocument(string $doc, Request $r, StatementDocumentService $docs): JsonResponse
    {
        $d = $r->validate(['subject_id' => 'required|uuid']);
        $f = $this->query->normalise($r->except('subject_id'));
        $document = $docs->generate($this->tenant->id(), strtoupper($doc), $d['subject_id'], $f, $r->user()->id);

        return response()->json(['data' => ['id' => $document->id, 'document_number' => $document->document_number, 'document_type_code' => $document->document_type_code,
            'spec_document' => strtoupper($doc), 'provenance' => $document->provenance]], 201);
    }

    private function csv(array $report, array $filters): Response
    {
        $csv = '# applied_filters: '.json_encode($filters)."\n".FinanceReportRegistry::toCsv($report + ['reason' => null]);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$report['code'].'-'.now()->format('Ymd').'.csv"']);
    }
}
