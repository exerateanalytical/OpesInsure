<?php

declare(strict_types=1);

namespace App\Application\Compliance\Catalogue\Http;

use App\Application\Compliance\Catalogue\ComplianceCatalogueReadiness;
use App\Application\Compliance\Catalogue\ComplianceCatalogueSeeder;
use App\Application\Compliance\Catalogue\ComplianceCatalogueService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Gap Closure Pack 08 / 09 catalogue API (KYC matrix, refresh policies, country risk, fraud indicators, report dictionary, AML/ICT controls). */
final class ComplianceCatalogueController
{
    public function __construct(private TenantContext $tenant, private ComplianceCatalogueService $svc) {}

    public function documentMatrix(Request $r): JsonResponse
    {
        $rows = DB::table('kyc_document_matrix')->when($r->query('customer_type'), fn ($q, $t) => $q->where('customer_type', strtoupper($t)))
            ->orderBy('customer_type')->orderBy('document_code')->get();

        return response()->json(['data' => $rows, 'meta' => ['customer_types' => ComplianceCatalogueSeeder::pack('08')['customer_types']]]);
    }

    public function refreshPolicies(): JsonResponse
    {
        return response()->json(['data' => $this->svc->refreshPolicies($this->tenant->id())]);
    }

    public function configureRefresh(Request $r, string $level): JsonResponse
    {
        $d = $r->validate(['refresh_months' => 'required|integer|min:1|max:120', 'source_policy_id' => 'required|string|max:128',
            'trigger_events' => 'sometimes|array', 'trigger_events.*' => 'string', 'effective_from' => 'sometimes|date', 'effective_until' => 'nullable|date|after:effective_from']);

        return response()->json(['data' => $this->svc->configureRefresh($this->tenant->id(), $level, $d, $r->user())], 201);
    }

    public function approveRefresh(Request $r, string $id): JsonResponse
    {
        return response()->json(['data' => $this->svc->approveRefresh($this->tenant->id(), $id, $r->user())]);
    }

    public function countryRisk(): JsonResponse
    {
        $t = $this->tenant->id();

        return response()->json(['data' => DB::table('country_risk_ratings')->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $t))
            ->orderBy('country_code')->orderByDesc('effective_from')->get()]);
    }

    public function fraudIndicators(): JsonResponse
    {
        return response()->json(['data' => DB::table('fraud_indicators')->orderBy('category')->orderBy('code')->get(),
            'meta' => ['rule' => ComplianceCatalogueSeeder::pack('08')['fraud_rule']]]);
    }

    public function flag(Request $r, string $code): JsonResponse
    {
        $d = $r->validate(['subject_type' => 'required|string|max:64', 'subject_id' => 'required|uuid', 'facts' => 'sometimes|array']);

        return response()->json(['data' => $this->svc->flag($this->tenant->id(), strtoupper($code), $d['subject_type'], $d['subject_id'], $d['facts'] ?? [], $r->user())], 201);
    }

    public function dictionary(Request $r): JsonResponse
    {
        return response()->json(['data' => DB::table('regulatory_report_dictionary_lines')->when($r->query('report_code'), fn ($q, $c) => $q->where('report_code', $c))
            ->orderBy('report_code')->orderBy('line_code')->paginate(min(max((int) $r->query('per_page', 100), 1), 500))]);
    }

    public function controls(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->svc->controls($r->query('framework'), $this->tenant->id()),
            'meta' => ['statuses' => ComplianceCatalogueService::CONTROL_STATUSES]]);
    }

    public function assessments(string $control): JsonResponse
    {
        return response()->json(['data' => DB::table('compliance_control_assessments')->where('tenant_id', $this->tenant->id())->where('control_id', $control)
            ->orderByDesc('assessed_at')->orderByDesc('seq')->get()]);
    }

    public function assess(Request $r, string $control): JsonResponse
    {
        $d = $r->validate(['status' => 'required|string', 'evidence' => 'sometimes|array', 'notes' => 'nullable|string|max:10000',
            'compliance_finding_id' => 'nullable|uuid']);

        return response()->json(['data' => $this->svc->assess($this->tenant->id(), $control, $d, $r->user())], 201);
    }

    public function readiness(): JsonResponse
    {
        return response()->json(['data' => [...ComplianceCatalogueReadiness::kyc(), ...ComplianceCatalogueReadiness::fraud(), ...ComplianceCatalogueReadiness::reporting(),
            ...ComplianceCatalogueReadiness::controls('ict_controls', 'ICT', 'cima_010_24_control_taxonomy', 'CIMA Reg. 010-24')]]);
    }
}
