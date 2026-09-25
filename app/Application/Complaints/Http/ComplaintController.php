<?php

declare(strict_types=1);

namespace App\Application\Complaints\Http;

use App\Application\Cases\CaseService;
use App\Application\Cases\Models\WorkCase;
use App\Application\Complaints\ComplaintService;
use App\Application\Correspondence\CorrespondenceService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * REQ-CPL-001 complaint API. {complaint} is the COMPLAINT case id: the case is the aggregate, reads go
 * through WorkCase (tenant + confidentiality scope), every state change through ComplaintService → CaseService.
 */
final class ComplaintController
{
    public function __construct(private readonly ComplaintService $complaints, private readonly CaseService $cases) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['status' => 'nullable|string|max:48', 'category' => 'nullable|string|max:64', 'severity' => 'nullable|string|max:16',
            'regulatory' => 'nullable|boolean', 'open' => 'nullable|boolean', 'per_page' => 'nullable|integer|min:1|max:100']);
        $q = DB::table('complaints as p')->join('cases as c', 'c.id', '=', 'p.case_id')
            ->whereIn('c.id', WorkCase::query()->where('tenant_id', $this->tenant())->select('id'))
            ->when($d['status'] ?? null, fn ($q, $v) => $q->where('c.status', $v))
            ->when($d['category'] ?? null, fn ($q, $v) => $q->where('p.category', $v))
            ->when($d['severity'] ?? null, fn ($q, $v) => $q->where('p.severity', $v))
            ->when(isset($d['regulatory']), fn ($q) => $q->where('p.regulatory', $r->boolean('regulatory')))
            ->when(isset($d['open']), fn ($q) => $r->boolean('open') ? $q->whereNull('c.closed_at') : $q->whereNotNull('c.closed_at'))
            ->orderByRaw('c.due_at NULLS LAST')->orderByDesc('p.received_at')
            ->select(['p.*', 'c.case_number', 'c.status', 'c.priority', 'c.owner_user_id', 'c.queue_id', 'c.due_at', 'c.closed_at']);

        return response()->json($q->paginate($d['per_page'] ?? 25));
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'complainant_name' => 'required|string|max:200', 'complainant_contact' => 'nullable|string|max:255', 'party_id' => 'nullable|uuid',
            'channel' => ['required', Rule::in(CorrespondenceService::CHANNELS)], 'description' => 'required|string|min:10|max:10000',
            'subject_type' => 'nullable|string|max:64', 'subject_id' => 'nullable|uuid|required_with:subject_type', 'regulatory' => 'nullable|boolean',
            'branch_id' => 'nullable|uuid', 'received_at' => 'nullable|date|before_or_equal:now', 'idempotency_key' => 'nullable|string|max:100',
        ]);
        $d['idempotency_key'] ??= $r->header('Idempotency-Key');
        $existing = ! empty($d['idempotency_key']) && DB::table('complaints')->where('tenant_id', $this->tenant())->where('idempotency_key', $d['idempotency_key'])->exists();
        $c = $this->complaints->submit($this->tenant(), $d, $r->user());

        return response()->json(['data' => $this->present($this->find($c->case_id), $r)], $existing ? 200 : 201);
    }

    public function fromTicket(Request $r): JsonResponse
    {
        $d = $r->validate(['support_ticket_id' => 'required|uuid']);
        $existing = DB::table('complaints')->where('tenant_id', $this->tenant())->where('support_ticket_id', $d['support_ticket_id'])->exists();
        $c = $this->complaints->fromSupportTicket($this->tenant(), $d['support_ticket_id'], $r->user());

        return response()->json(['data' => $this->present($this->find($c->case_id), $r)], $existing ? 200 : 201);
    }

    public function show(Request $r, string $complaint): JsonResponse
    {
        $case = $this->find($complaint);
        $out = $this->present($case, $r);
        $out['correspondence'] = DB::table('correspondence_register')->where('case_id', $case->id)->orderBy('created_at')->get();
        $out['decisions'] = $case->decisions()->get();
        $out['sla_clocks'] = $case->clocks()->orderBy('metric')->get();

        return response()->json(['data' => $out]);
    }

    public function acknowledge(Request $r, string $complaint): JsonResponse
    {
        $this->complaints->acknowledge($this->find($complaint), $r->user());

        return $this->ok($complaint, $r);
    }

    public function classify(Request $r, string $complaint): JsonResponse
    {
        $d = $r->validate(['category' => 'required|string|max:64', 'severity' => 'required|in:LOW,MEDIUM,HIGH,CRITICAL', 'regulatory' => 'nullable|boolean']);
        $this->complaints->classify($this->find($complaint), $d['category'], $d['severity'], isset($d['regulatory']) ? $r->boolean('regulatory') : null, $r->user());

        return $this->ok($complaint, $r);
    }

    public function assign(Request $r, string $complaint): JsonResponse
    {
        $d = $r->validate(['owner_user_id' => 'required|uuid', 'reason' => 'nullable|string|max:500']);
        $this->complaints->assignInvestigator($this->find($complaint), $d['owner_user_id'], $r->user(), $d['reason'] ?? null);

        return $this->ok($complaint, $r);
    }

    public function investigate(Request $r, string $complaint): JsonResponse
    {
        $this->complaints->investigate($this->find($complaint), $r->user());

        return $this->ok($complaint, $r);
    }

    public function resolution(Request $r, string $complaint): JsonResponse
    {
        $d = $r->validate(['outcome' => 'required|in:UPHELD,PARTIALLY_UPHELD,NOT_UPHELD', 'resolution_summary' => 'required|string|min:10|max:10000',
            'root_cause' => 'nullable|string|max:64', 'redress_amount' => 'nullable|numeric|min:0']);
        $this->complaints->proposeResolution($this->find($complaint), $d, $r->user());

        return $this->ok($complaint, $r);
    }

    public function communicate(Request $r, string $complaint): JsonResponse
    {
        $d = $r->validate(['correspondence_id' => 'required|uuid']);
        $this->complaints->communicate($this->find($complaint), $d['correspondence_id'], $r->user());

        return $this->ok($complaint, $r);
    }

    public function escalate(Request $r, string $complaint): JsonResponse
    {
        $d = $r->validate(['level' => 'required|in:NATIONAL,CIMA', 'reason' => 'required|string|max:1000', 'reference' => 'nullable|string|max:120']);
        $this->complaints->escalate($this->find($complaint), $d['level'], $d['reason'], $d['reference'] ?? null, $r->user());

        return $this->ok($complaint, $r);
    }

    public function transition(Request $r, string $complaint): JsonResponse
    {
        $d = $r->validate(['event' => 'required|in:request_info,info_received,reinvestigate,close', 'reason' => 'nullable|string|max:1000']);
        $this->complaints->advance($this->find($complaint), $d['event'], $r->user(), $d['reason'] ?? null);

        return $this->ok($complaint, $r);
    }

    private function ok(string $id, Request $r): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($id), $r)]);
    }

    /** @return array<string, mixed> */
    private function present(WorkCase $case, Request $r): array
    {
        return ['complaint' => $this->complaints->forCase($case), 'case' => $case->toArray() + ['available_events' => $this->cases->availableEvents($case, $r->user())]];
    }

    private function find(string $id): WorkCase
    {
        return WorkCase::query()->where('tenant_id', $this->tenant())->where('case_type_code', 'COMPLAINT')->whereKey($id)->firstOrFail();
    }

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }
}
