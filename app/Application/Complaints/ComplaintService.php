<?php

declare(strict_types=1);

namespace App\Application\Complaints;

use App\Application\Cases\Bridges\LegacyWorkItemBridge;
use App\Application\Cases\CaseJournal;
use App\Application\Cases\CaseProblem;
use App\Application\Cases\CaseService;
use App\Application\Cases\CaseTypeCatalogue;
use App\Application\Cases\Models\WorkCase;
use App\Application\Correspondence\CorrespondenceService;
use App\Domain\Shared\Clock\Clock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-CPL-001 complaints, separate from support, riding the case engine (REQ-CAS-001):
 * a complaint IS a COMPLAINT case (state, owner, queue, SLA clocks, decisions, journal) plus a `complaints`
 * row for the complaint facts. No status is written here; every step is CaseService::transition, whose
 * guards (ComplaintLifecycle) check the facts.
 *
 * REQ-DUP-022: a complaint raised as a support ticket is consolidated through LegacyWorkItemBridge (the one
 * ticket → COMPLAINT case path); the ticket becomes a read projection whose status is mirrored from the case.
 */
final class ComplaintService
{

    /** Case status → support ticket status mirror (REQ-CAS-002). */
    private const TICKET_MIRROR = [
        'ACKNOWLEDGED' => 'TRIAGED', 'CLASSIFIED' => 'TRIAGED', 'ASSIGNED' => 'IN_PROGRESS', 'INVESTIGATING' => 'IN_PROGRESS',
        'WAITING_CUSTOMER' => 'WAITING_CUSTOMER', 'RESOLUTION_PROPOSED' => 'IN_PROGRESS', 'COMMUNICATED' => 'RESOLVED',
        'ESCALATED_NATIONAL' => 'ESCALATED', 'ESCALATED_CIMA' => 'ESCALATED', 'CLOSED' => 'CLOSED',
    ];

    public function __construct(
        private readonly CaseService $cases,
        private readonly CaseJournal $journal,
        private readonly LegacyWorkItemBridge $bridge,
        private readonly CorrespondenceService $correspondence,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array{complainant_name: string, complainant_contact?: ?string, party_id?: ?string, channel: string, description: string,
     *   subject_type?: ?string, subject_id?: ?string, regulatory?: bool, branch_id?: ?string, received_at?: ?string, idempotency_key?: ?string}  $d
     */
    public function submit(string $tenantId, array $d, ?User $actor): object
    {
        if (! empty($d['idempotency_key']) && ($c = DB::table('complaints')->where('tenant_id', $tenantId)->where('idempotency_key', $d['idempotency_key'])->first())) {
            return $c;
        }
        if (! empty($d['party_id']) && ! DB::table('tenant_customers')->where('tenant_id', $tenantId)->where('party_id', $d['party_id'])->exists()) {
            throw CaseProblem::make('PARTY_NOT_FOUND', 422, 'The complainant is not a customer of this tenant.');
        }

        return DB::transaction(function () use ($tenantId, $d, $actor) {
            $regulatory = (bool) ($d['regulatory'] ?? false);
            $case = $this->cases->open($tenantId, 'COMPLAINT', [
                'title' => 'Complaint: '.mb_strimwidth($d['description'], 0, 120, '…'), 'case_subtype' => $regulatory ? 'REGULATORY' : 'STANDARD',
                'subject_type' => $d['subject_type'] ?? null, 'subject_id' => $d['subject_id'] ?? null, 'branch_id' => $d['branch_id'] ?? null,
                'idempotency_key' => isset($d['idempotency_key']) ? 'complaint:'.$d['idempotency_key'] : null,
            ], $actor);
            $complaint = $this->createRow($tenantId, $case, $d + ['regulatory' => $regulatory], $actor);
            // Proof of receipt: the complaint itself is inbound correspondence on the case.
            $this->correspondence->register($tenantId, [
                'case_id' => $case->id, 'direction' => 'INBOUND', 'channel' => $d['channel'], 'counterparty_type' => 'CUSTOMER',
                'counterparty_party_id' => $d['party_id'] ?? null, 'counterparty_name' => $d['complainant_name'], 'counterparty_contact' => $d['complainant_contact'] ?? null,
                'subject_line' => 'Complaint '.$complaint->complaint_number, 'summary' => $d['description'], 'received_at' => $complaint->received_at,
            ], $actor);

            return $complaint;
        });
    }

    /** REQ-DUP-022: consolidate a COMPLAINT / REGULATORY_COMPLAINT support ticket onto the case engine (idempotent). */
    public function fromSupportTicket(string $tenantId, string $ticketId, ?User $actor): object
    {
        return DB::transaction(function () use ($tenantId, $ticketId, $actor) {
            $type = DB::table('support_tickets')->where('id', $ticketId)->where('tenant_id', $tenantId)->value('type')
                ?? throw CaseProblem::make('SOURCE_NOT_FOUND', 404, 'support_tickets row not found in this tenant.');
            if (! in_array($type, ['COMPLAINT', 'REGULATORY_COMPLAINT'], true)) {
                throw CaseProblem::make('SOURCE_NOT_BRIDGEABLE', 422, 'Only complaint tickets are bridged to COMPLAINT cases.');
            }
            if ($c =DB::table('complaints')->where('tenant_id', $tenantId)->where('support_ticket_id', $ticketId)->first()) {
                return $c;
            }
            $case = $this->bridge->link('support_tickets', $ticketId, $tenantId, $actor)['case'];
            if ($c = DB::table('complaints')->where('case_id', $case->id)->first()) {
                return $c;
            }
            $t = DB::table('support_tickets')->where('id', $ticketId)->first();
            $party = $t->party_id ? DB::table('parties')->where('id', $t->party_id)->first() : null;

            return $this->createRow($tenantId, $case, [
                'complainant_name' => $party->display_name ?? $party->legal_name ?? $party->full_name ?? 'Ticket '.$t->ticket_number,
                'party_id' => $t->party_id, 'channel' => 'PORTAL', 'description' => $t->description,
                'regulatory' => $t->type === 'REGULATORY_COMPLAINT', 'support_ticket_id' => $t->id, 'received_at' => $t->created_at,
            ], $actor);
        });
    }

    public function acknowledge(WorkCase $case, ?User $actor): object
    {
        return $this->step($case, 'acknowledge', $actor, null, fn () => ['acknowledged_at' => $this->clock->now()]);
    }

    public function classify(WorkCase $case, string $category, string $severity, ?bool $regulatory, ?User $actor): object
    {
        if (! isset(CaseTypeCatalogue::COMPLAINT_SEVERITY_MAP[$severity])) {
            throw CaseProblem::make('SEVERITY_INVALID', 422, 'Unknown complaint severity.', ['allowed' => array_keys(CaseTypeCatalogue::COMPLAINT_SEVERITY_MAP)]);
        }

        return DB::transaction(function () use ($case, $category, $severity, $regulatory, $actor) {
            $complaint = $this->complaint($case);
            $regulatory ??= (bool) $complaint->regulatory;
            DB::table('complaints')->where('id', $complaint->id)->update(['category' => $category, 'severity' => $severity, 'regulatory' => $regulatory, 'classified_at' => $this->clock->now(), 'updated_at' => $this->clock->now()]);
            $locked = $this->cases->lock($case->id);
            $priority = CaseTypeCatalogue::COMPLAINT_SEVERITY_MAP[$severity];
            if ($locked->priority !== $priority) {
                $old = $locked->priority;
                $locked->update(['priority' => $priority, 'version' => $locked->version + 1]);
                $this->journal->event($locked, 'PRIORITY_CHANGED', ['from' => $old, 'to' => $priority, 'reason' => 'COMPLAINT_SEVERITY_'.$severity], null, null, $actor?->id);
            }
            $subtype = $regulatory ? 'REGULATORY' : 'STANDARD';
            if (in_array($subtype, (array) ($locked->type->subtypes ?? []), true) && $locked->case_subtype !== $subtype) {
                $this->cases->reclassify($locked, $subtype, $actor, 'Complaint classification');
            }

            return $this->step($case, 'classify', $actor);
        });
    }

    public function assignInvestigator(WorkCase $case, string $userId, ?User $actor, ?string $reason = null): object
    {
        return DB::transaction(function () use ($case, $userId, $actor, $reason) {
            $case = $this->cases->assign($case, $userId, null, $actor, $reason ?? 'COMPLAINT_INVESTIGATOR');

            return $this->step($case, 'assign_investigator', $actor);
        });
    }

    public function investigate(WorkCase $case, ?User $actor): object
    {
        return $this->step($case, 'investigate', $actor);
    }

    /** @param array{outcome: string, resolution_summary: string, root_cause?: ?string, redress_amount?: ?float} $d */
    public function proposeResolution(WorkCase $case, array $d, User $actor): object
    {
        return DB::transaction(function () use ($case, $d, $actor) {
            $complaint = $this->complaint($case);
            $decision = $this->cases->decide($case, [
                'decision_type' => 'COMPLAINT_RESOLUTION', 'outcome' => $d['outcome'], 'rationale' => $d['resolution_summary'],
                'conditions' => array_filter(['root_cause' => $d['root_cause'] ?? null, 'redress_amount' => $d['redress_amount'] ?? null], fn ($v) => $v !== null),
            ], $actor);
            DB::table('complaints')->where('id', $complaint->id)->update([
                'outcome' => $d['outcome'], 'resolution_summary' => $d['resolution_summary'], 'root_cause' => $d['root_cause'] ?? null,
                'redress_amount' => $d['redress_amount'] ?? null, 'updated_at' => $this->clock->now(),
            ]);

            return $this->step($case, 'propose_resolution', $actor, null, fn () => [], ['decision_id' => $decision->id]);
        });
    }

    /** The response must already be registered and dispatched with proof (REQ-COR-001). */
    public function communicate(WorkCase $case, string $correspondenceId, ?User $actor): object
    {
        $row = DB::table('correspondence_register')->where('id', $correspondenceId)->where('case_id', $case->id)->first();
        if (! $row || $row->direction !== 'OUTBOUND') {
            throw CaseProblem::make('CORRESPONDENCE_NOT_ON_CASE', 422, 'The response must be an outbound correspondence registered on this complaint case.');
        }

        return DB::transaction(function () use ($case, $row, $actor) {
            DB::table('complaints')->where('case_id', $case->id)->update(['response_correspondence_id' => $row->id, 'communicated_at' => $row->dispatched_at ?? $this->clock->now(), 'updated_at' => $this->clock->now()]);

            return $this->step($case, 'communicate', $actor, null, fn () => [], ['correspondence_id' => $row->id]);
        });
    }

    public function escalate(WorkCase $case, string $level, string $reason, ?string $reference, ?User $actor): object
    {
        $event = ['NATIONAL' => 'escalate_national', 'CIMA' => 'escalate_cima'][$level] ?? throw CaseProblem::make('ESCALATION_LEVEL_INVALID', 422, 'Escalation level must be NATIONAL or CIMA.');

        return $this->step($case, $event, $actor, $reason, fn () => ['escalation_level' => $level, 'escalation_reference' => $reference, 'escalated_at' => $this->clock->now()]);
    }

    public function advance(WorkCase $case, string $event, ?User $actor, ?string $reason = null): object
    {
        return $this->step($case, $event, $actor, $reason);
    }

    public function forCase(WorkCase $case): object
    {
        return $this->complaint($case);
    }

    /**
     * One case transition + complaint facts + ticket mirror in one transaction.
     *
     * @param  (\Closure(): array<string, mixed>)|null  $facts
     * @param  array<string, mixed>  $payload
     */
    private function step(WorkCase $case, string $event, ?User $actor, ?string $reason = null, ?\Closure $facts = null, array $payload = []): object
    {
        return DB::transaction(function () use ($case, $event, $actor, $reason, $facts, $payload) {
            $complaint = $this->complaint($case);
            $case = $this->cases->transition($case, $event, $actor, $reason, $payload);
            $update = $facts ? $facts() : [];
            if ($update !== []) {
                DB::table('complaints')->where('id', $complaint->id)->update($update + ['updated_at' => $this->clock->now()]);
            }
            $this->mirrorTicket($complaint, $case, $actor, $event);

            return $this->complaint($case);
        });
    }

    private function mirrorTicket(object $complaint, WorkCase $case, ?User $actor, string $event): void
    {
        $to = self::TICKET_MIRROR[$case->status] ?? null;
        $ticket = $complaint->support_ticket_id ? DB::table('support_tickets')->where('id', $complaint->support_ticket_id)->lockForUpdate()->first() : null;
        if (! $ticket || $to === null || $ticket->status === $to) {
            return;
        }
        $now = $this->clock->now();
        DB::table('support_tickets')->where('id', $ticket->id)->update(array_filter([
            'status' => $to, 'updated_at' => $now,
            'acknowledged_at' => $to === 'TRIAGED' && empty($ticket->acknowledged_at) ? $now : null,
            'resolved_at' => $to === 'RESOLVED' ? $now : null, 'closed_at' => $to === 'CLOSED' ? $now : null,
        ], fn ($v) => $v !== null));
        DB::table('support_ticket_events')->insert([
            'id' => (string) Str::uuid(), 'support_ticket_id' => $ticket->id, 'type' => 'CASE_MIRROR', 'from_status' => $ticket->status, 'to_status' => $to,
            'actor_id' => $actor?->id, 'message' => "Mirrored from complaint case {$case->case_number} ({$event})",
            'metadata' => json_encode(['case_id' => $case->id, 'case_status' => $case->status]), 'occurred_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $d */
    private function createRow(string $tenantId, WorkCase $case, array $d, ?User $actor): object
    {
        $now = $this->clock->now();
        $id = (string) Str::uuid();
        DB::table('complaints')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'case_id' => $case->id, 'complaint_number' => 'CPL-'.$now->format('Ym').'-'.strtoupper(Str::random(8)),
            'party_id' => $d['party_id'] ?? null, 'complainant_name' => $d['complainant_name'], 'complainant_contact' => $d['complainant_contact'] ?? null,
            'channel' => $d['channel'], 'subject_type' => $d['subject_type'] ?? $case->subject_type, 'subject_id' => $d['subject_id'] ?? $case->subject_id,
            'description' => $d['description'], 'regulatory' => (bool) ($d['regulatory'] ?? false), 'support_ticket_id' => $d['support_ticket_id'] ?? null,
            'received_at' => $d['received_at'] ?? $now, 'created_by' => $actor?->id, 'idempotency_key' => $d['idempotency_key'] ?? null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->journal->event($this->cases->lock($case->id), 'COMPLAINT_REGISTERED', ['complaint_id' => $id, 'support_ticket_id' => $d['support_ticket_id'] ?? null], null, null, $actor?->id);
        $this->journal->publish('complaint.submitted', 'case', $case->id, ['complaint_id' => $id, 'regulatory' => (bool) ($d['regulatory'] ?? false)]);

        return DB::table('complaints')->where('id', $id)->first();
    }

    private function complaint(WorkCase $case): object
    {
        return DB::table('complaints')->where('case_id', $case->id)->first()
            ?? throw CaseProblem::make('COMPLAINT_NOT_FOUND', 404, 'This case has no complaint record.');
    }
}
