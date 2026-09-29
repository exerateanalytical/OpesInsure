<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Complaints\ComplaintService;
use App\Application\Identity\PartyResolver;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SHR-013 Complaint Form / SHR-014 Complaint Details for the signed-in customer (web /account/complaints, app).
 * Thin own-party layer over the REQ-CPL-001 complaint engine: filing goes through ComplaintService::submit (the same
 * case + correspondence path the back office uses, channel PORTAL); reads are restricted to complaints whose
 * party_id is the caller's Party in the current tenant — anything else is a 404. Internal case data (owner, queue,
 * decisions, SLA clocks) is not exposed; the customer sees status, dates, outcome and the correspondence with them.
 */
final class MobileComplaintController
{
    private const CLOSED = ['CLOSED', 'RESOLVED', 'CANCELLED', 'WITHDRAWN'];

    public function __construct(private readonly PartyResolver $parties, private readonly ComplaintService $complaints) {}

    public function index(Request $request): JsonResponse
    {
        $party = $this->parties->forUser($request->user());
        if (! $party) {
            return response()->json(['data' => []]);
        }
        $rows = $this->base()->where('p.party_id', $party->id)->orderByDesc('p.received_at')->limit(50)->get();

        return response()->json(['data' => $rows->map(fn ($r) => $this->present($r))->values()]);
    }

    public function show(string $complaint, Request $request): JsonResponse
    {
        $row = $this->owned($complaint, $request);
        $out = $this->present($row);
        $out['timeline'] = DB::table('case_events')->where('case_id', $row->case_id)->orderBy('seq')
            ->get(['type', 'from_status', 'to_status', 'occurred_at'])->map(fn ($e) => (array) $e)->values();
        $out['correspondence'] = DB::table('correspondence_register')->where('case_id', $row->case_id)->where('counterparty_type', 'CUSTOMER')->orderBy('created_at')
            ->get(['reference_number', 'direction', 'channel', 'subject_line', 'summary', 'status', 'received_at', 'dispatched_at', 'created_at'])->map(fn ($c) => (array) $c)->values();

        return response()->json(['data' => $out]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'description' => 'required|string|min:10|max:10000',
            'policy_id' => 'nullable|uuid', 'claim_id' => 'nullable|uuid',
            'contact' => 'nullable|string|max:255',
        ]);
        $party = $this->parties->forUser($request->user());
        abort_unless($party, 422, 'No customer identity is linked to this account.');
        $tenant = app(TenantContext::class)->id();
        [$subjectType, $subjectId] = $this->subject($data, $party->id, $tenant);
        $user = $request->user();
        $key = $request->header('Idempotency-Key');

        $c = $this->complaints->submit($tenant, [
            'complainant_name' => $party->display_name ?: ($user->full_name ?? 'Customer'),
            'complainant_contact' => $data['contact'] ?? ($user->email ?? $user->phone_e164 ?? null),
            'party_id' => $party->id, 'channel' => 'PORTAL', 'description' => $data['description'],
            'subject_type' => $subjectType, 'subject_id' => $subjectId, 'idempotency_key' => $key ? 'customer:'.$user->id.':'.$key : null,
        ], $user);

        return response()->json(['data' => $this->present($this->base()->where('p.id', $c->id)->first())], 201);
    }

    /** @return array{0: ?string, 1: ?string} only the caller's own policy / claim may be the subject */
    private function subject(array $data, string $partyId, string $tenant): array
    {
        if (! empty($data['claim_id'])) {
            DB::table('claims as c')->leftJoin('policies as p', 'p.id', '=', 'c.policy_id')->where('c.id', $data['claim_id'])->where('c.tenant_id', $tenant)
                ->where(fn ($q) => $q->where('c.claimant_party_id', $partyId)->orWhere('p.party_id', $partyId))->exists()
                || throw ValidationException::withMessages(['claim_id' => 'Not found among your records.']);

            return ['claim', $data['claim_id']];
        }
        if (! empty($data['policy_id'])) {
            DB::table('policies')->where(['id' => $data['policy_id'], 'tenant_id' => $tenant, 'party_id' => $partyId])->exists()
                || throw ValidationException::withMessages(['policy_id' => 'Not found among your records.']);

            return ['policy', $data['policy_id']];
        }

        return [null, null];
    }

    private function owned(string $id, Request $request): object
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id), 404);
        $party = $this->parties->forUser($request->user());
        abort_unless($party, 404);

        return $this->base()->where('p.party_id', $party->id)->where(fn ($q) => $q->where('p.id', $id)->orWhere('p.case_id', $id))->first() ?? abort(404);
    }

    private function base(): \Illuminate\Database\Query\Builder
    {
        return DB::table('complaints as p')->join('cases as c', 'c.id', '=', 'p.case_id')->where('p.tenant_id', app(TenantContext::class)->id())
            ->select(['p.id', 'p.case_id', 'p.complaint_number', 'p.channel', 'p.subject_type', 'p.subject_id', 'p.description', 'p.category', 'p.regulatory',
                'p.received_at', 'p.acknowledged_at', 'p.outcome', 'p.resolution_summary', 'p.communicated_at', 'p.escalation_level', 'p.escalated_at',
                'c.status', 'c.due_at', 'c.closed_at']);
    }

    private function present(object $r): array
    {
        return [
            'id' => $r->id, 'case_id' => $r->case_id, 'complaint_number' => $r->complaint_number, 'status' => $r->status,
            'open' => $r->closed_at === null && ! in_array(strtoupper((string) $r->status), self::CLOSED, true),
            'channel' => $r->channel, 'subject_type' => $r->subject_type, 'subject_id' => $r->subject_id, 'description' => $r->description,
            'category' => $r->category, 'received_at' => $r->received_at, 'acknowledged_at' => $r->acknowledged_at, 'due_at' => $r->due_at,
            'outcome' => $r->outcome, 'resolution_summary' => $r->resolution_summary, 'communicated_at' => $r->communicated_at,
            'escalation_level' => $r->escalation_level, 'escalated_at' => $r->escalated_at, 'closed_at' => $r->closed_at,
        ];
    }
}
