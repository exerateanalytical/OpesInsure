<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Audit\AuditWriter;
use App\Application\Identity\PartyResolver;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * support/* screens on top of support_tickets + support_ticket_events.
 * A ticket is owned by the caller's Party (party_id), which is how every
 * customer-facing mobile endpoint scopes ownership; the back-office
 * SupportController keeps handling triage/transitions.
 */
final class MobileSupportController
{
    public function __construct(private PartyResolver $parties, private AuditWriter $audit) {}

    public function index(Request $request): JsonResponse
    {
        $party = $this->parties->forUser($request->user());
        if (! $party) {
            return response()->json(['data' => []]);
        }
        $rows = DB::table('support_tickets')->where(['tenant_id' => app(TenantContext::class)->id(), 'party_id' => $party->id])->orderByDesc('created_at')->limit(50)->get();

        return response()->json(['data' => $rows->map(fn ($t) => $this->present($t, false))->values()]);
    }

    public function show(string $case, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->owned($case, $request), true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => 'required|string|max:64', 'subject' => 'required|string|min:3|max:200', 'description' => 'required|string|min:10|max:10000',
            'claim_id' => 'nullable|uuid', 'payment_id' => 'nullable|uuid', 'policy_id' => 'nullable|uuid', 'parent_case_id' => 'nullable|uuid',
        ]);
        $party = $this->parties->forUser($request->user());
        abort_unless($party, 422, 'No customer identity is linked to this account.');
        $tenant = app(TenantContext::class)->id();
        $links = $this->ownedLinks($data, $party->id, $tenant, $request);
        $key = $request->header('Idempotency-Key') ?: (string) Str::uuid();
        if ($existing = DB::table('support_tickets')->where(['tenant_id' => $tenant, 'idempotency_key' => $key])->first()) {
            return response()->json(['data' => $this->present($existing, true)]);
        }
        $id = (string) Str::uuid();
        $priority = in_array(strtoupper($data['category']), ['PAYMENT', 'CLAIM', 'FRAUD', 'SECURITY'], true) ? 'HIGH' : 'NORMAL';
        DB::transaction(function () use ($data, $id, $tenant, $party, $request, $key, $priority, $links) {
            DB::table('support_tickets')->insert([
                'id' => $id, 'tenant_id' => $tenant, 'party_id' => $party->id, 'ticket_number' => 'TKT-'.now()->format('Ym').'-'.strtoupper(Str::random(8)),
                'type' => 'SUPPORT', 'category' => strtoupper($data['category']), 'priority' => $priority, 'status' => 'OPEN', 'subject' => $data['subject'], 'description' => $data['description'],
                'sla_due_at' => now()->addHours($priority === 'HIGH' ? 12 : 48), 'idempotency_key' => $key, 'created_at' => now(), 'updated_at' => now(),
            ] + $links);
            $this->event($id, 'CREATED', $data['description'], $request->user()->id, 'CUSTOMER', null, 'OPEN');
            $this->audit->record('support.ticket.created', 'support_ticket', $id, ['channel' => 'MOBILE']);
        });

        return response()->json(['data' => $this->present(DB::table('support_tickets')->find($id), true)], 201);
    }

    public function message(string $case, Request $request): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|min:1|max:5000']);
        $ticket = $this->owned($case, $request);
        abort_if(in_array($ticket->status, ['CLOSED', 'CANCELLED'], true), 422, 'This case is closed.');
        $this->event($ticket->id, 'MESSAGE', $data['body'], $request->user()->id, 'CUSTOMER');
        DB::table('support_tickets')->where('id', $ticket->id)->update(['updated_at' => now(), 'status' => $ticket->status === 'WAITING_CUSTOMER' ? 'IN_PROGRESS' : $ticket->status]);

        return response()->json(['data' => $this->present(DB::table('support_tickets')->find($ticket->id), true)], 201);
    }

    public function attachment(string $case, Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:10240|mimes:jpg,jpeg,png,pdf']);
        $ticket = $this->owned($case, $request);
        $file = $request->file('file');
        $party = $this->parties->forUser($request->user());
        $key = $file->store('support/'.$ticket->id, 'local');
        $docId = (string) Str::uuid();
        DB::table('documents')->insert([
            'id' => $docId, 'tenant_id' => $ticket->tenant_id, 'party_id' => $party->id, 'category' => 'SUPPORT_ATTACHMENT', 'storage_key' => $key,
            'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'scan_status' => 'PENDING', 'verification_status' => 'PENDING', 'ocr_data' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->event($ticket->id, 'ATTACHMENT', $file->getClientOriginalName(), $request->user()->id, 'CUSTOMER', ['document_id' => $docId, 'file_name' => $file->getClientOriginalName()]);

        return response()->json(['data' => $this->present(DB::table('support_tickets')->find($ticket->id), true)], 201);
    }

    /**
     * POST /mobile/support/cases/{case}/escalate { reason? } — raises priority
     * to URGENT, tightens the SLA and flags the case for a supervisor.
     * Idempotent: an already-escalated case is returned unchanged.
     */
    public function escalate(string $case, Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:2000']);
        $ticket = $this->owned($case, $request);
        abort_if(in_array($ticket->status, ['CLOSED', 'CANCELLED', 'RESOLVED'], true), 422, 'This case is closed.');
        if (! $ticket->escalated_at) {
            DB::transaction(function () use ($ticket, $data, $request) {
                DB::table('support_tickets')->where('id', $ticket->id)->update([
                    'priority' => 'URGENT', 'escalated_at' => now(), 'updated_at' => now(),
                    'sla_due_at' => min(\Carbon\Carbon::parse($ticket->sla_due_at), now()->addHours(4)),
                ]);
                $this->event($ticket->id, 'ESCALATED', $data['reason'] ?? 'Escalated by the customer.', $request->user()->id, 'CUSTOMER');
                $this->audit->record('support.ticket.escalated', 'support_ticket', $ticket->id, ['channel' => 'MOBILE']);
            });
        }

        return response()->json(['data' => $this->present(DB::table('support_tickets')->find($ticket->id), true)]);
    }

    /** Only the caller's own claim/payment/policy/case may be linked; anything else is a 422. */
    private function ownedLinks(array $data, string $partyId, string $tenant, Request $request): array
    {
        $links = [];
        $fail = fn (string $field) => throw \Illuminate\Validation\ValidationException::withMessages([$field => 'Not found among your records.']);
        if (! empty($data['claim_id'])) {
            DB::table('claims as c')->leftJoin('policies as p', 'p.id', '=', 'c.policy_id')->where('c.id', $data['claim_id'])->where('c.tenant_id', $tenant)
                ->where(fn ($q) => $q->where('c.claimant_party_id', $partyId)->orWhere('p.party_id', $partyId))->exists() || $fail('claim_id');
            $links['claim_id'] = $data['claim_id'];
        }
        if (! empty($data['payment_id'])) {
            DB::table('payment_intents as i')->join('proposals as pr', 'pr.id', '=', 'i.proposal_id')->where('i.id', $data['payment_id'])->where('i.tenant_id', $tenant)->where('pr.party_id', $partyId)->exists() || $fail('payment_id');
            $links['payment_intent_id'] = $data['payment_id'];
        }
        if (! empty($data['policy_id'])) {
            DB::table('policies')->where(['id' => $data['policy_id'], 'tenant_id' => $tenant, 'party_id' => $partyId])->exists() || $fail('policy_id');
            $links['policy_id'] = $data['policy_id'];
        }
        if (! empty($data['parent_case_id'])) {
            DB::table('support_tickets')->where(['id' => $data['parent_case_id'], 'tenant_id' => $tenant, 'party_id' => $partyId])->exists() || $fail('parent_case_id');
            $links['related_ticket_id'] = $data['parent_case_id'];
        }

        return $links;
    }

    private function owned(string $id, Request $request): object
    {
        $party = $this->parties->forUser($request->user());
        $ticket = $party ? DB::table('support_tickets')->where(['id' => $id, 'tenant_id' => app(TenantContext::class)->id(), 'party_id' => $party->id])->first() : null;
        abort_unless($ticket, 404);

        return $ticket;
    }

    private function event(string $ticketId, string $type, string $message, string $actorId, string $sender, ?array $metadata = null, ?string $toStatus = null): void
    {
        DB::table('support_ticket_events')->insert([
            'id' => (string) Str::uuid(), 'support_ticket_id' => $ticketId, 'type' => $type, 'to_status' => $toStatus, 'actor_id' => $actorId, 'message' => $message,
            'metadata' => json_encode(['sender' => $sender] + ($metadata ?? [])), 'occurred_at' => now(),
        ]);
    }

    private function present(object $t, bool $withThread): array
    {
        $events = $withThread ? DB::table('support_ticket_events')->where('support_ticket_id', $t->id)->orderBy('occurred_at')->get() : collect();
        $messages = $events->filter(fn ($e) => in_array($e->type, ['CREATED', 'MESSAGE', 'STATUS_CHANGED'], true))->map(function ($e) {
            $meta = json_decode($e->metadata ?? '{}', true) ?: [];
            $sender = $meta['sender'] ?? ($e->type === 'CREATED' ? 'CUSTOMER' : 'SUPPORT');

            return ['id' => $e->id, 'sender' => $sender === 'CUSTOMER' ? 'CUSTOMER' : 'SUPPORT', 'body' => $e->message, 'created_at' => \Carbon\Carbon::parse($e->occurred_at)->toIso8601String()];
        })->values();
        $attachments = $events->where('type', 'ATTACHMENT')->map(fn ($e) => ['id' => json_decode($e->metadata, true)['document_id'] ?? $e->id, 'file_name' => $e->message, 'status' => 'RECEIVED'])->values();

        return [
            'id' => $t->id, 'reference' => $t->ticket_number, 'category' => $t->category, 'subject' => $t->subject, 'description' => $t->description,
            'status' => $t->status, 'priority' => $t->priority, 'created_at' => \Carbon\Carbon::parse($t->created_at)->toIso8601String(), 'updated_at' => \Carbon\Carbon::parse($t->updated_at)->toIso8601String(),
            'messages' => $messages, 'attachments' => $attachments,
            'claim_id' => $t->claim_id ?? null, 'payment_id' => $t->payment_intent_id ?? null, 'policy_id' => $t->policy_id ?? null,
            'parent_case_id' => $t->related_ticket_id ?? null, 'escalated' => ! empty($t->escalated_at),
            'escalated_at' => ! empty($t->escalated_at) ? \Carbon\Carbon::parse($t->escalated_at)->toIso8601String() : null,
        ];
    }
}
