<?php

declare(strict_types=1);

namespace App\Application\Cases\Http;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseProblem;
use App\Application\Cases\CaseTypeService;
use App\Application\Cases\CaseVisibility;
use App\Application\Cases\Models\CalendarBusinessHours;
use App\Application\Cases\Models\CalendarException;
use App\Application\Cases\Models\CaseType;
use App\Application\Cases\Models\QueueMember;
use App\Application\Cases\Models\WorkQueue;
use App\Application\Cases\Sla\BusinessHoursCalendar;
use App\Application\Temporal\BusinessTime;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** REQ-CAS-001 / REQ-CAL-001 admin API: /admin/case-types, /admin/calendars, /admin/queues (ICE §6.9). */
final class CaseAdminController
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function draftType(Request $r, CaseTypeService $types): JsonResponse
    {
        $d = $r->validate([
            'code' => 'required|string|max:48|regex:/^[A-Z][A-Z0-9_]*$/', 'name' => 'nullable|string|max:160', 'family_code' => 'nullable|string|max:48',
            'states' => 'required|array|min:1', 'transitions' => 'required|array|min:1', 'sla_policies' => 'nullable|array', 'auto_tasks' => 'nullable|array',
            'default_confidentiality' => ['nullable', Rule::in(CaseVisibility::LEVELS)], 'regulated' => 'nullable|boolean',
        ]);

        return response()->json(['data' => $types->draft($d, $r->user())], 201);
    }

    public function approveType(Request $r, string $type, CaseTypeService $types): JsonResponse
    {
        return response()->json(['data' => $types->approve(CaseType::findOrFail($type), $r->user())]);
    }

    public function hours(Request $r): JsonResponse
    {
        $d = $r->validate(['jurisdiction' => 'nullable|string|size:2', 'branch_id' => 'nullable|uuid']);

        return response()->json(['data' => CalendarBusinessHours::query()
            ->when($d['jurisdiction'] ?? null, fn ($q, $v) => $q->where('jurisdiction', $v))
            ->when($d['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->orderBy('jurisdiction')->orderBy('weekday')->orderBy('opens')->get()]);
    }

    public function addHours(Request $r): JsonResponse
    {
        $d = $r->validate([
            'jurisdiction' => 'required|string|size:2', 'branch_id' => 'nullable|uuid', 'weekday' => 'required|integer|between:1,7',
            'opens' => 'required|date_format:H:i', 'closes' => 'required|date_format:H:i|after:opens', 'valid_from' => 'required|date', 'valid_to' => 'nullable|date|after_or_equal:valid_from',
        ]);
        $this->assertBranch($d['branch_id'] ?? null);
        $row = CalendarBusinessHours::create($d + ['created_by' => $r->user()->id]);
        $this->audit->record('calendar.business_hours.added', 'calendar_business_hours', $row->id, $d);

        return response()->json(['data' => $row], 201);
    }

    /** Hours are never deleted; they are end-dated (history stays explainable). */
    public function endHours(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['valid_to' => 'required|date']);
        $row = CalendarBusinessHours::findOrFail($id);
        if ($row->branch_id) {
            $this->assertBranch($row->branch_id);
        }
        $old = ['valid_to' => $row->valid_to?->toDateString()];
        $row->update(['valid_to' => $d['valid_to']]);
        $this->audit->recordChange('calendar.business_hours.ended', 'calendar_business_hours', $row->id, $old, $d, 'END_DATED');

        return response()->json(['data' => $row->refresh()]);
    }

    public function exceptions(Request $r): JsonResponse
    {
        $d = $r->validate(['jurisdiction' => 'nullable|string|size:2', 'year' => 'nullable|integer|min:2000|max:2100']);

        return response()->json(['data' => CalendarException::query()
            ->when($d['jurisdiction'] ?? null, fn ($q, $v) => $q->where('jurisdiction', $v))
            ->when($d['year'] ?? null, fn ($q, $v) => $q->whereYear('date', $v))
            ->orderBy('date')->get()]);
    }

    public function addException(Request $r): JsonResponse
    {
        $d = $r->validate([
            'jurisdiction' => 'required|string|size:2', 'branch_id' => 'nullable|uuid', 'date' => 'required|date_format:Y-m-d',
            'kind' => 'required|in:HOLIDAY,CLOSURE,EXTRA_DAY', 'label' => 'required|string|max:160', 'source_reference' => 'nullable|string|max:255',
        ]);
        $this->assertBranch($d['branch_id'] ?? null);
        $row = CalendarException::create($d + ['created_by' => $r->user()->id]);
        $this->audit->record('calendar.exception.added', 'calendar_exception', $row->id, $d);

        return response()->json(['data' => $row], 201);
    }

    /** Diagnostic: due instant for N business minutes from `from` (ICE §6.11 test 1 by hand). */
    public function businessTime(Request $r, BusinessHoursCalendar $calendar): JsonResponse
    {
        $d = $r->validate(['from' => 'required|date', 'minutes' => 'required|integer|min:0|max:5256000', 'jurisdiction' => 'nullable|string|size:2', 'branch_id' => 'nullable|uuid', 'timezone' => 'nullable|timezone']);
        $j = $d['jurisdiction'] ?? 'CM';
        $tz = $d['timezone'] ?? $calendar->timezone($d['branch_id'] ?? null);
        $due = $calendar->addBusinessMinutes($d['from'], (int) $d['minutes'], $j, $d['branch_id'] ?? null, $tz);

        return response()->json(['data' => ['from' => BusinessTime::parse($d['from'], $tz)->toIso8601String(), 'minutes' => (int) $d['minutes'], 'due_at' => $due->toIso8601String(), 'hours_source' => $calendar->hoursSource($j, $d['branch_id'] ?? null)]]);
    }

    public function addQueue(Request $r): JsonResponse
    {
        $tenant = app(TenantContext::class)->id();
        $d = $r->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('queues')->where('tenant_id', $tenant)], 'name' => 'required|string|max:160',
            'case_type_codes' => 'nullable|array', 'case_type_codes.*' => 'string|max:48', 'branch_id' => 'nullable|uuid',
            'routing_rule' => 'nullable|in:PULL,LEAST_LOADED,ROUND_ROBIN', 'is_default' => 'nullable|boolean',
        ]);
        $this->assertBranch($d['branch_id'] ?? null);
        $q = WorkQueue::create($d + ['tenant_id' => $tenant, 'case_type_codes' => $d['case_type_codes'] ?? [], 'routing_rule' => $d['routing_rule'] ?? 'PULL']);
        $this->audit->record('queue.created', 'queue', $q->id, ['code' => $q->code]);

        return response()->json(['data' => $q->refresh()], 201);
    }

    public function addMember(Request $r, string $queue): JsonResponse
    {
        $tenant = app(TenantContext::class)->id();
        $q = WorkQueue::where('tenant_id', $tenant)->findOrFail($queue);
        $d = $r->validate(['user_id' => 'required|uuid', 'capacity' => 'nullable|integer|min:1|max:1000', 'skills' => 'nullable|array', 'active' => 'nullable|boolean']);
        if (! DB::table('tenant_memberships')->where('tenant_id', $tenant)->where('user_id', $d['user_id'])->where('status', 'ACTIVE')->exists()) {
            throw CaseProblem::make('ASSIGNEE_NOT_IN_TENANT', 422, 'Queue members must be active members of the tenant.');
        }
        $m = QueueMember::updateOrCreate(['queue_id' => $q->id, 'user_id' => $d['user_id']], ['capacity' => $d['capacity'] ?? 20, 'skills' => $d['skills'] ?? [], 'active' => $d['active'] ?? true]);
        $this->audit->record('queue.member.saved', 'queue', $q->id, ['user_id' => $d['user_id'], 'active' => $m->active]);

        return response()->json(['data' => $m], 201);
    }

    private function assertBranch(?string $branchId): void
    {
        if ($branchId && ! DB::table('tenant_branches')->where('tenant_id', app(TenantContext::class)->id())->where('id', $branchId)->exists()) {
            throw CaseProblem::make('BRANCH_NOT_FOUND', 422, 'Branch not found in this tenant.');
        }
    }
}
