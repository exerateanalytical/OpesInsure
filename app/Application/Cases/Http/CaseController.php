<?php

declare(strict_types=1);

namespace App\Application\Cases\Http;

use App\Application\Cases\Bridges\LegacyWorkItemBridge;
use App\Application\Cases\Bridges\MyWorkProjection;
use App\Application\Cases\CaseProblem;
use App\Application\Cases\CaseService;
use App\Application\Cases\CaseVisibility;
use App\Application\Cases\DiaryService;
use App\Application\Cases\Models\CaseTask;
use App\Application\Cases\Models\CaseType;
use App\Application\Cases\Models\WorkCase;
use App\Application\Cases\Models\WorkQueue;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * REQ-CAS-001 case API (ICE §6.9). Every read goes through WorkCase, whose
 * global scope filters by confidentiality (INV-6.5); tenant scoping is explicit.
 * State changes are delegated to CaseService (no status writes here, REQ-ARC-008).
 */
final class CaseController
{
    public function __construct(private readonly CaseService $cases) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate([
            'status' => 'nullable|string|max:48', 'case_type' => 'nullable|string|max:48', 'queue_id' => 'nullable|uuid',
            'owner' => 'nullable|in:me,unassigned', 'open' => 'nullable|boolean', 'subject_type' => 'nullable|string|max:64', 'subject_id' => 'nullable|uuid',
            'per_page' => 'nullable|integer|min:1|max:100',
            'case_family' => 'nullable|string|max:48', 'case_subtype' => 'nullable|string|max:48', 'domain_reference' => 'nullable|string|max:191',
        ]);
        $q = WorkCase::query()->where('tenant_id', $this->tenant())
            ->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($d['case_type'] ?? null, fn ($q, $v) => $q->where('case_type_code', $v))
            ->when($d['queue_id'] ?? null, fn ($q, $v) => $q->where('queue_id', $v))
            ->when(($d['owner'] ?? null) === 'me', fn ($q) => $q->where('owner_user_id', $r->user()->id))
            ->when(($d['owner'] ?? null) === 'unassigned', fn ($q) => $q->whereNull('owner_user_id'))
            ->when(isset($d['open']), fn ($q) => $r->boolean('open') ? $q->whereNull('closed_at') : $q->whereNotNull('closed_at'))
            ->when($d['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($d['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($d['case_family'] ?? null, fn ($q, $v) => $q->where('case_family', $v))
            ->when($d['case_subtype'] ?? null, fn ($q, $v) => $q->where('case_subtype', $v))
            ->when($d['domain_reference'] ?? null, fn ($q, $v) => $q->where('domain_reference', $v))
            ->orderByRaw('due_at NULLS LAST')->orderByDesc('opened_at');

        return response()->json($q->paginate($d['per_page'] ?? 25));
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'case_type' => 'required|string|max:48', 'title' => 'required|string|max:255',
            'priority' => ['nullable', Rule::in(\App\Application\Cases\CaseTypeCatalogue::PRIORITIES)], 'confidentiality' => ['nullable', Rule::in(CaseVisibility::LEVELS)],
            'subject_type' => 'nullable|string|max:64', 'subject_id' => 'nullable|uuid|required_with:subject_type',
            'parent_case_id' => 'nullable|uuid', 'branch_id' => 'nullable|uuid', 'jurisdiction' => 'nullable|string|size:2',
            'owner_user_id' => 'nullable|uuid', 'queue_id' => 'nullable|uuid', 'idempotency_key' => 'nullable|string|max:100',
            'case_subtype' => 'nullable|string|max:48', 'domain_reference' => 'nullable|string|max:191', 'product_id' => 'nullable|uuid',
        ]);
        $tenant = $this->tenant();
        if (! empty($d['parent_case_id']) && ! WorkCase::query()->where('tenant_id', $tenant)->whereKey($d['parent_case_id'])->exists()) {
            throw CaseProblem::make('PARENT_CASE_NOT_FOUND', 422, 'Parent case not found.');
        }
        if (! empty($d['branch_id']) && ! DB::table('tenant_branches')->where('tenant_id', $tenant)->where('id', $d['branch_id'])->exists()) {
            throw CaseProblem::make('BRANCH_NOT_FOUND', 422, 'Branch not found in this tenant.');
        }
        $d['idempotency_key'] ??= $r->header('Idempotency-Key');
        $case = $this->cases->open($tenant, $d['case_type'], $d, $r->user());

        return response()->json(['data' => $this->present($case, $r)], $case->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $r, string $case): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($case), $r, true)]);
    }

    public function transition(Request $r, string $case): JsonResponse
    {
        $d = $r->validate(['event' => 'required|string|max:64', 'reason' => 'nullable|string|max:1000', 'outcome' => 'nullable|string|max:32', 'payload' => 'nullable|array']);
        $c = $this->find($case);
        $payload = ($d['payload'] ?? []) + (isset($d['outcome']) ? ['outcome' => $d['outcome']] : []);
        $c = $this->cases->transition($c, $d['event'], $r->user(), $d['reason'] ?? null, $payload);

        return response()->json(['data' => $this->present($c, $r)]);
    }

    public function assign(Request $r, string $case): JsonResponse
    {
        $d = $r->validate(['owner_user_id' => 'nullable|uuid|required_without:queue_id', 'queue_id' => 'nullable|uuid', 'reason' => 'nullable|string|max:500']);
        $c = $this->cases->assign($this->find($case), $d['owner_user_id'] ?? null, $d['queue_id'] ?? null, $r->user(), $d['reason'] ?? null);

        return response()->json(['data' => $this->present($c, $r)]);
    }

    public function events(string $case): JsonResponse
    {
        return response()->json(['data' => $this->find($case)->events()->get()]);
    }

    public function tasks(string $case): JsonResponse
    {
        return response()->json(['data' => $this->find($case)->tasks()->orderByRaw('due_at NULLS LAST')->get()]);
    }

    public function addTask(Request $r, string $case): JsonResponse
    {
        $d = $r->validate([
            'title' => 'required|string|max:255', 'template_code' => 'nullable|string|max:64', 'assignee_user_id' => 'nullable|uuid',
            'queue_id' => 'nullable|uuid', 'due_at' => 'nullable|date', 'due_in_business_minutes' => 'nullable|integer|min:1|max:5256000',
        ]);

        return response()->json(['data' => $this->cases->addTask($this->find($case), $d, $r->user())], 201);
    }

    public function transitionTask(Request $r, string $case, string $task): JsonResponse
    {
        $d = $r->validate(['status' => 'required|in:IN_PROGRESS,BLOCKED,DONE,CANCELLED', 'result' => 'nullable|array']);
        $c = $this->find($case);
        $t = CaseTask::where('case_id', $c->id)->findOrFail($task);

        return response()->json(['data' => $this->cases->transitionTask($t, $d['status'], $r->user(), $d['result'] ?? null)]);
    }

    public function decisions(string $case): JsonResponse
    {
        return response()->json(['data' => $this->find($case)->decisions()->get()]);
    }

    public function decide(Request $r, string $case): JsonResponse
    {
        $d = $r->validate([
            'decision_type' => 'required|string|max:48', 'outcome' => 'required|string|max:48', 'rationale' => 'required|string|max:5000',
            'conditions' => 'nullable|array', 'reverses_decision_id' => 'nullable|uuid',
        ]);

        return response()->json(['data' => $this->cases->decide($this->find($case), $d, $r->user())], 201);
    }

    public function diary(string $case): JsonResponse
    {
        return response()->json(['data' => DB::table('diary_entries')->where('case_id', $this->find($case)->id)->orderBy('created_at')->get()]);
    }

    public function addDiary(Request $r, string $case, DiaryService $diary): JsonResponse
    {
        $d = $r->validate([
            'entry_type' => 'required|in:NOTE,CALL,MEETING,FOLLOW_UP', 'body' => 'required|string|max:10000',
            'follow_up_at' => 'nullable|date|required_if:entry_type,FOLLOW_UP', 'visibility' => 'nullable|in:INTERNAL,SHARED', 'supersedes_entry_id' => 'nullable|uuid',
        ]);

        return response()->json(['data' => $diary->add($this->find($case), $d, $r->user())], 201);
    }

    public function myWork(Request $r, MyWorkProjection $work): JsonResponse
    {
        return response()->json(['data' => $work->for($r->user(), $this->tenant())]);
    }

    public function queues(): JsonResponse
    {
        return response()->json(['data' => WorkQueue::where('tenant_id', $this->tenant())->where('active', true)->orderBy('code')->get()]);
    }

    public function next(Request $r, string $queue): JsonResponse
    {
        $q = WorkQueue::where('tenant_id', $this->tenant())->where('active', true)->findOrFail($queue);
        $c = $this->cases->pullNext($q, $r->user());

        return $c ? response()->json(['data' => $this->present($c, $r)]) : response()->json(['data' => null], 200);
    }

    public function caseTypes(): JsonResponse
    {
        return response()->json(['data' => CaseType::orderBy('code')->orderByDesc('version')->get()]);
    }

    public function link(Request $r, LegacyWorkItemBridge $bridge): JsonResponse
    {
        $d = $r->validate(['source' => ['required', Rule::in(LegacyWorkItemBridge::SOURCES)], 'id' => 'required|uuid']);
        $out = $bridge->link($d['source'], $d['id'], $this->tenant(), $r->user());

        return response()->json(['data' => ['case_id' => $out['case']->id, 'case_number' => $out['case']->case_number, 'case_task_id' => $out['task']?->id, 'created' => $out['created']]], $out['created'] ? 201 : 200);
    }

    private function find(string $id): WorkCase
    {
        // Out-of-tenant and confidential-invisible cases are indistinguishable from missing ones (404).
        return WorkCase::query()->where('tenant_id', $this->tenant())->whereKey($id)->firstOrFail();
    }

    private function present(WorkCase $c, Request $r, bool $full = false): array
    {
        $out = $c->toArray() + ['available_events' => $this->cases->availableEvents($c, $r->user())];
        if ($full) {
            $type = CaseType::find($c->case_type_id);
            $out['case_type'] = ['code' => $type->code, 'version' => $type->version, 'family_code' => $type->family_code, 'name' => $type->name, 'states' => $type->states];
            $out['sla_clocks'] = $c->clocks()->orderBy('metric')->get();
            $out['open_tasks'] = $c->tasks()->whereIn('status', CaseTask::OPEN_STATES)->count();
        }

        return $out;
    }

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }
}
