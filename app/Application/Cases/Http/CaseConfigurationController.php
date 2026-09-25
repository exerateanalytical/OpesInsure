<?php

declare(strict_types=1);

namespace App\Application\Cases\Http;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseProblem;
use App\Application\Cases\CaseService;
use App\Application\Cases\CaseTypeCatalogue;
use App\Application\Cases\Models\CaseType;
use App\Application\Cases\Models\WorkCase;
use App\Application\Cases\Sla\SlaPolicyResolver;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Owner decisions #13 / #22 / #32 / complaints:
 *  - GET  case-families                      reporting/navigation families (+ case types per family)
 *  - POST cases/{case}/subtype               reclassify (re-targets SLA clocks)
 *  - GET  cases/{case}/sla-policies          resolved SLA policies with label + source
 *  - admin/sla-overrides                     per insurer / product / case type / branch / market SLA targets
 *  - admin/calendars/breaks                  optional lunch/break exclusion per calendar (never inferred)
 */
final class CaseConfigurationController
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function families(): JsonResponse
    {
        $types = CaseType::where('status', 'EFFECTIVE')->get(['code', 'name', 'family_code', 'subtypes'])->groupBy('family_code');

        return response()->json(['data' => DB::table('case_families')->where('active', true)->orderBy('sort_order')->get()
            ->map(fn ($f) => (array) $f + ['case_types' => ($types[$f->code] ?? collect())->values()])]);
    }

    public function reclassify(Request $r, string $case, CaseService $cases): JsonResponse
    {
        $d = $r->validate(['case_subtype' => 'nullable|string|max:48', 'reason' => 'required|string|min:5|max:2000']);
        $row = WorkCase::query()->where('tenant_id', $this->tenant())->findOrFail($case);
        $out = $cases->reclassify($row, $d['case_subtype'] ?? null, $r->user(), $d['reason']);

        return response()->json(['data' => ['case' => $out['case'], 'retargeted' => $out['retargeted']]]);
    }

    public function slaPolicies(string $case, SlaPolicyResolver $resolver): JsonResponse
    {
        $row = WorkCase::query()->where('tenant_id', $this->tenant())->findOrFail($case);

        return response()->json(['data' => array_values($resolver->resolve($row, CaseType::findOrFail($row->case_type_id)))]);
    }

    public function overrides(Request $r): JsonResponse
    {
        $d = $r->validate(['case_type_code' => 'nullable|string|max:48', 'status' => 'nullable|in:ACTIVE,RETIRED']);

        return response()->json(['data' => DB::table('sla_policy_overrides')
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $this->tenant()))
            ->when($d['case_type_code'] ?? null, fn ($q, $v) => $q->where('case_type_code', $v))
            ->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('case_type_code')->orderBy('metric')->get()]);
    }

    public function addOverride(Request $r): JsonResponse
    {
        $d = $r->validate([
            'case_type_code' => 'required|string|max:48', 'metric' => 'required|string|max:64',
            'carrier_id' => 'nullable|uuid', 'product_id' => 'nullable|uuid', 'branch_id' => 'nullable|uuid', 'market' => 'nullable|string|size:2',
            'case_subtype' => 'nullable|string|max:48',
            'target_business_minutes' => 'nullable|integer|min:1|max:5256000|required_without:target_business_days|prohibits:target_business_days',
            'target_business_days' => 'nullable|integer|min:1|max:3650|required_without:target_business_minutes',
            'warn_at_pct' => 'nullable|integer|between:1,99', 'escalate_to' => 'nullable|string|max:64',
            'label' => ['nullable', Rule::in(CaseTypeCatalogue::LABELS)], 'legal_basis' => 'nullable|string|max:2000',
            'effective_from' => 'nullable|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from',
        ]);
        $label = $d['label'] ?? 'PLATFORM_SLA';
        if ($label === 'REGULATORY_DEADLINE' && trim((string) ($d['legal_basis'] ?? '')) === '') {
            throw CaseProblem::make('LEGAL_BASIS_REQUIRED', 422, 'A REGULATORY_DEADLINE needs a legal basis; internal targets are PLATFORM_SLA.');
        }
        $type = CaseType::where('code', $d['case_type_code'])->orderByDesc('version')->first()
            ?? throw CaseProblem::make('CASE_TYPE_NOT_FOUND', 422, 'Unknown case type.');
        $metric = $d['metric'];
        if (! in_array($metric, ['FIRST_RESPONSE', 'RESOLUTION'], true) && ! (str_starts_with($metric, 'STAGE:') && array_key_exists(substr($metric, 6), $type->stateMap()))) {
            throw CaseProblem::make('SLA_METRIC_INVALID', 422, "Unknown SLA metric {$metric}.");
        }
        if (! empty($d['case_subtype']) && ($type->subtypes ?? []) !== [] && ! in_array($d['case_subtype'], $type->subtypes, true)) {
            throw CaseProblem::make('CASE_SUBTYPE_INVALID', 422, 'Sub-type not defined for this case type.', ['allowed' => $type->subtypes]);
        }
        if (! empty($d['branch_id']) && ! DB::table('tenant_branches')->where('tenant_id', $this->tenant())->where('id', $d['branch_id'])->exists()) {
            throw CaseProblem::make('BRANCH_NOT_FOUND', 422, 'Branch not found in this tenant.');
        }
        $id = (string) Str::uuid();
        $row = [
            'id' => $id, 'case_type_code' => $d['case_type_code'], 'metric' => $metric, 'tenant_id' => $this->tenant(),
            'carrier_id' => $d['carrier_id'] ?? null, 'product_id' => $d['product_id'] ?? null, 'branch_id' => $d['branch_id'] ?? null,
            'market' => $d['market'] ?? null, 'case_subtype' => $d['case_subtype'] ?? null,
            'target_business_minutes' => $d['target_business_minutes'] ?? null, 'target_business_days' => $d['target_business_days'] ?? null,
            'warn_at_pct' => $d['warn_at_pct'] ?? 80, 'escalate_to' => $d['escalate_to'] ?? null,
            'deadline_label' => $label, 'legal_basis' => $d['legal_basis'] ?? null,
            'effective_from' => $d['effective_from'] ?? now()->toDateString(), 'effective_until' => $d['effective_until'] ?? null,
            'status' => 'ACTIVE', 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('sla_policy_overrides')->insert($row);
        $this->audit->record('sla_policy_override.created', 'sla_policy_override', $id, array_diff_key($row, array_flip(['created_at', 'updated_at'])));

        return response()->json(['data' => DB::table('sla_policy_overrides')->find($id)], 201);
    }

    public function retireOverride(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:2000']);
        $n = DB::table('sla_policy_overrides')->where('id', $id)->where('tenant_id', $this->tenant())->where('status', 'ACTIVE')
            ->update(['status' => 'RETIRED', 'effective_until' => now()->toDateString(), 'updated_at' => now()]);
        if ($n !== 1) {
            throw CaseProblem::make('SLA_OVERRIDE_NOT_ACTIVE', 409, 'Override not found or already retired.');
        }
        $this->audit->record('sla_policy_override.retired', 'sla_policy_override', $id, [], $d['reason']);

        return response()->json(['data' => DB::table('sla_policy_overrides')->find($id)]);
    }

    public function breaks(Request $r): JsonResponse
    {
        $d = $r->validate(['jurisdiction' => 'nullable|string|size:2', 'branch_id' => 'nullable|uuid']);

        return response()->json(['data' => DB::table('calendar_breaks')
            ->when($d['jurisdiction'] ?? null, fn ($q, $v) => $q->where('jurisdiction', $v))
            ->when($d['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->orderBy('jurisdiction')->orderBy('starts')->get()]);
    }

    public function addBreak(Request $r): JsonResponse
    {
        $d = $r->validate([
            'jurisdiction' => 'required|string|size:2', 'branch_id' => 'nullable|uuid', 'weekday' => 'nullable|integer|between:1,7',
            'starts' => 'required|date_format:H:i', 'ends' => 'required|date_format:H:i|after:starts', 'label' => 'nullable|string|max:120',
            'valid_from' => 'required|date', 'valid_to' => 'nullable|date|after_or_equal:valid_from',
        ]);
        if (! empty($d['branch_id']) && ! DB::table('tenant_branches')->where('tenant_id', $this->tenant())->where('id', $d['branch_id'])->exists()) {
            throw CaseProblem::make('BRANCH_NOT_FOUND', 422, 'Branch not found in this tenant.');
        }
        $id = (string) Str::uuid();
        DB::table('calendar_breaks')->insert($d + ['id' => $id, 'label' => $d['label'] ?? 'Break', 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('calendar.break.added', 'calendar_break', $id, $d);

        return response()->json(['data' => DB::table('calendar_breaks')->find($id)], 201);
    }

    /** Breaks are end-dated, never deleted. */
    public function endBreak(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['valid_to' => 'required|date']);
        $row = DB::table('calendar_breaks')->find($id) ?? abort(404);
        if ($row->branch_id && ! DB::table('tenant_branches')->where('tenant_id', $this->tenant())->where('id', $row->branch_id)->exists()) {
            abort(404);
        }
        DB::table('calendar_breaks')->where('id', $id)->update(['valid_to' => $d['valid_to'], 'updated_at' => now()]);
        $this->audit->recordChange('calendar.break.ended', 'calendar_break', $id, ['valid_to' => $row->valid_to], $d, 'END_DATED');

        return response()->json(['data' => DB::table('calendar_breaks')->find($id)]);
    }

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }
}
