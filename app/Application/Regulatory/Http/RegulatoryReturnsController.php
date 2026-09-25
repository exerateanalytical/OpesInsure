<?php

declare(strict_types=1);

namespace App\Application\Regulatory\Http;

use App\Application\Compliance\RegulatoryReportingService;
use App\Application\Regulatory\Inspection\InspectionWorkspaceService;
use App\Application\Regulatory\Profitability\PolicyProfitabilityReport;
use App\Application\Regulatory\Returns\RegulatoryReturnGenerator;
use App\Application\Regulatory\Rules\RegulatoryRuleService;
use App\Domain\Tenancy\TenantContext;
use App\Models\RegulatoryReportDefinition;
use App\Models\RegulatoryReportRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Agent B1 — REQ-RPT-001 returns + lineage, REQ-RPT-002 regulatory change engine, REQ-RPT-006 inspection workspace + profitability. */
final class RegulatoryReturnsController
{
    public function __construct(private TenantContext $tenant) {}

    // ---- REQ-RPT-001 -------------------------------------------------------------------------------------------

    public function define(Request $r, RegulatoryReportingService $reports, RegulatoryReturnGenerator $gen): JsonResponse
    {
        $d = $r->validate([
            'code' => 'required|string|max:80', 'version' => 'required|integer|min:1', 'jurisdiction' => 'required|string|max:8',
            'report_type' => 'required|string|max:64', 'regime' => 'nullable|string|max:16', 'description' => 'nullable|string|max:2000',
            'regulatory_category_kind' => 'nullable|required_with:regulatory_category_code|string|in:ART_411_CATEGORY,ART_557_MEASURE',
            'regulatory_category_code' => 'nullable|string|max:64', 'schema' => 'required|array',
            'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from',
        ]);
        $gen->validateSchema($d['schema']);
        if (isset($d['regulatory_category_code']) && ! DB::table('regulatory_reporting_categories')
            ->where(['kind' => $d['regulatory_category_kind'], 'code' => $d['regulatory_category_code']])->exists()) {
            throw ValidationException::withMessages(['regulatory_category_code' => 'Unknown regulatory reporting category for this kind.']);
        }

        return response()->json(['data' => $reports->define($d, $r->user())], 201);
    }

    public function approveDefinition(Request $r, RegulatoryReportDefinition $definition, RegulatoryReportingService $reports): JsonResponse
    {
        return response()->json(['data' => $reports->approveDefinition($definition, $r->user())]);
    }

    public function definitions(): JsonResponse
    {
        return response()->json(['data' => RegulatoryReportDefinition::orderBy('code')->orderByDesc('version')->get()]);
    }

    public function generate(Request $r, RegulatoryReportDefinition $definition, RegulatoryReturnGenerator $gen): JsonResponse
    {
        $d = $r->validate(['period_key' => 'required|string|max:40', 'period_from' => 'required|date', 'period_to' => 'required|date|after_or_equal:period_from',
            'idempotency_key' => 'required|string|max:100']);

        return response()->json(['data' => $gen->generate($this->tenant->id(), $definition, $d, $r->user())], 201);
    }

    public function run(RegulatoryReportRun $run): JsonResponse
    {
        return response()->json(['data' => $this->owned($run)]);
    }

    public function lineage(Request $r, RegulatoryReportRun $run, RegulatoryReturnGenerator $gen): JsonResponse
    {
        $row = $r->validate(['row' => 'nullable|integer|min:0'])['row'] ?? null;

        return response()->json(['data' => $gen->lineage($this->owned($run), $row === null ? null : (int) $row)]);
    }

    // ---- REQ-RPT-002 -------------------------------------------------------------------------------------------

    public function draftRule(Request $r, RegulatoryRuleService $rules): JsonResponse
    {
        $d = $r->validate([
            'jurisdiction' => 'nullable|string|max:8', 'code' => 'required|string|max:80', 'title' => 'required|string|max:255',
            'rule_type' => 'required|string|max:48', 'legal_reference' => 'nullable|string|max:255', 'reference_set_code' => 'nullable|string|max:80',
            'scope' => 'nullable|array', 'content' => 'nullable|array',
            'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from',
        ]);

        return response()->json(['data' => $rules->draft($d, $r->user())], 201);
    }

    public function rules(Request $r): JsonResponse
    {
        $f = $r->validate(['code' => 'nullable|string|max:80', 'status' => ['nullable', Rule::in(RegulatoryRuleService::STATUSES)]]);

        return response()->json(['data' => DB::table('regulatory_rules')->when($f['code'] ?? null, fn ($q, $v) => $q->where('code', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->orderBy('code')->orderByDesc('version')
            ->get(['id', 'jurisdiction', 'code', 'version', 'title', 'rule_type', 'status', 'effective_from', 'effective_until', 'impact_hash'])]);
    }

    public function rule(string $rule, RegulatoryRuleService $rules): JsonResponse
    {
        return response()->json(['data' => $rules->find($rule)]);
    }

    public function impact(string $rule, RegulatoryRuleService $rules): JsonResponse
    {
        $impact = $rules->analyse($rules->find($rule));

        return response()->json(['data' => $impact, 'impact_hash' => hash('sha256', json_encode($impact, JSON_THROW_ON_ERROR))]);
    }

    public function reviewRule(Request $r, string $rule, RegulatoryRuleService $rules): JsonResponse
    {
        return response()->json(['data' => $rules->review($rule, $r->user(), $r->validate(['notes' => 'nullable|string|max:2000'])['notes'] ?? null)]);
    }

    public function approveRule(Request $r, string $rule, RegulatoryRuleService $rules): JsonResponse
    {
        return response()->json(['data' => $rules->approve($rule, $r->user(), $r->validate(['impact_hash' => 'required|string|size:64'])['impact_hash'])]);
    }

    public function activateRule(Request $r, string $rule, RegulatoryRuleService $rules): JsonResponse
    {
        return response()->json(['data' => $rules->activate($rule, $r->user())]);
    }

    public function referenceSetImpact(string $set, RegulatoryRuleService $rules): JsonResponse
    {
        return response()->json(['data' => $rules->referenceSetImpact($set)]);
    }

    // ---- REQ-RPT-006 -------------------------------------------------------------------------------------------

    public function openInspection(Request $r, InspectionWorkspaceService $ws): JsonResponse
    {
        $d = $r->validate([
            'inspector_user_id' => 'required|uuid', 'authority' => 'required|string|max:120', 'reference' => 'nullable|string|max:120',
            'justification' => 'required|string|max:2000', 'resources' => 'required|array|min:1',
            'resources.*' => ['string', Rule::in(array_keys(InspectionWorkspaceService::RESOURCES))],
            'starts_at' => 'required|date', 'expires_at' => 'required|date|after:starts_at',
        ]);
        $inspector = User::findOrFail($d['inspector_user_id']);
        abort_unless(DB::table('tenant_memberships')->where(['tenant_id' => $this->tenant->id(), 'user_id' => $inspector->id, 'status' => 'ACTIVE'])->exists(), 422, 'Inspector is not a member of this tenant.');
        if ($inspector->id === $r->user()->id) {
            throw ValidationException::withMessages(['inspector_user_id' => __('wave9.maker_checker')]);
        }

        return response()->json(['data' => $ws->open($this->tenant->id(), $inspector, $d, $r->user())], 201);
    }

    public function approveInspection(Request $r, string $inspection, InspectionWorkspaceService $ws): JsonResponse
    {
        return response()->json(['data' => $ws->approve($this->tenant->id(), $inspection, $r->user())]);
    }

    public function closeInspection(Request $r, string $inspection, InspectionWorkspaceService $ws): JsonResponse
    {
        return response()->json(['data' => $ws->close($this->tenant->id(), $inspection, $r->validate(['reason' => 'required|string|max:2000'])['reason'], $r->user())]);
    }

    public function inspection(string $inspection, InspectionWorkspaceService $ws): JsonResponse
    {
        return response()->json(['data' => $ws->find($this->tenant->id(), $inspection), 'exports' => $ws->exports($this->tenant->id(), $inspection)]);
    }

    public function inspectionRead(Request $r, string $inspection, string $resource, InspectionWorkspaceService $ws): JsonResponse
    {
        return response()->json(['data' => $ws->read($this->tenant->id(), $inspection, $resource, $this->filters($r), $r->user())]);
    }

    public function inspectionExport(Request $r, string $inspection, string $resource, InspectionWorkspaceService $ws): Response
    {
        $x = $ws->export($this->tenant->id(), $inspection, $resource, $this->filters($r), $r->user());

        return response($x['csv'], 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="'.$x['filename'].'"',
            'X-Export-Id' => $x['export_id'], 'X-Content-Hash' => $x['content_hash']]);
    }

    public function profitability(Request $r, PolicyProfitabilityReport $report): JsonResponse
    {
        $d = $r->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from', 'policy_id' => 'nullable|uuid']);

        return response()->json(['data' => $report->build($this->tenant->id(), CarbonImmutable::parse($d['from']), CarbonImmutable::parse($d['to']), $d['policy_id'] ?? null)]);
    }

    private function filters(Request $r): array
    {
        return $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'limit' => 'nullable|integer|min:1|max:1000']);
    }

    private function owned(RegulatoryReportRun $run): RegulatoryReportRun
    {
        abort_unless($run->tenant_id === $this->tenant->id(), 404);

        return $run;
    }
}
