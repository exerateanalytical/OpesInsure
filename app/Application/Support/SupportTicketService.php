<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Application\Audit\AuditWriter;
use App\Domain\Support\TicketStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Staff support tickets (POST support/tickets, POST support/tickets/{ticket}/transitions). One implementation shared
 * by the API controller and the staff desktop, so both open and move tickets identically (idempotency, SLA hours,
 * TicketStateMachine guard, event row and audit).
 */
final class SupportTicketService
{
    public function __construct(private readonly AuditWriter $audit, private readonly TicketStateMachine $machine) {}

    /**
     * @param  array{party_id?: ?string, type: string, category: string, priority: string, subject: string, description: string, idempotency_key: string}  $d
     * @return array{ticket: object|array<string, mixed>, created: bool}
     */
    public function open(string $tenantId, array $d, ?User $actor): array
    {
        if (isset($d['party_id'])) {
            abort_unless(DB::table('tenant_customers')->where(['tenant_id' => $tenantId, 'party_id' => $d['party_id']])->exists(), 404);
        }
        if ($o = DB::table('support_tickets')->where(['tenant_id' => $tenantId, 'idempotency_key' => $d['idempotency_key']])->first()) {
            return ['ticket' => $o, 'created' => false];
        }
        $id = (string) Str::uuid();
        $number = 'TKT-'.now()->format('Ym').'-'.strtoupper(Str::random(8));
        $hours = match ($d['priority']) {
            'URGENT' => 4, 'HIGH' => 12, 'NORMAL' => 48, default => 96,
        };
        DB::transaction(function () use ($d, $id, $number, $hours, $actor, $tenantId) {
            DB::table('support_tickets')->insert([...$d, 'id' => $id, 'tenant_id' => $tenantId, 'ticket_number' => $number, 'status' => 'OPEN',
                'sla_due_at' => now()->addHours($hours), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('support_ticket_events')->insert(['id' => (string) Str::uuid(), 'support_ticket_id' => $id, 'type' => 'CREATED', 'to_status' => 'OPEN',
                'actor_id' => $actor?->id, 'message' => $d['description'], 'metadata' => '{}', 'occurred_at' => now()]);
            $this->audit->record('support.ticket.created', 'support_ticket', $id, ['type' => $d['type'], 'priority' => $d['priority']]);
        });

        return ['ticket' => ['id' => $id, 'ticket_number' => $number, 'status' => 'OPEN'], 'created' => true];
    }

    /**
     * @param  array{to_status: string, message: string, assigned_to?: ?string}  $d
     * @return array{id: string, status: string}
     *
     * @throws \DomainException when TicketStateMachine refuses the move
     */
    public function transition(string $tenantId, string $ticketId, array $d, User $actor): array
    {
        return DB::transaction(function () use ($tenantId, $ticketId, $d, $actor) {
            $o = DB::table('support_tickets')->where(['id' => $ticketId, 'tenant_id' => $tenantId])->lockForUpdate()->first();
            abort_unless($o, 404);
            $this->machine->assert($o->status, $d['to_status']);
            $u = ['status' => $d['to_status'], 'updated_at' => now()];
            if (! empty($d['assigned_to'])) {
                $u['assigned_to'] = $d['assigned_to'];
            }
            if ($d['to_status'] === 'RESOLVED') {
                $u['resolved_at'] = now();
            }
            if ($d['to_status'] === 'CLOSED') {
                $u['closed_at'] = now();
            }
            DB::table('support_tickets')->where('id', $ticketId)->update($u);
            DB::table('support_ticket_events')->insert(['id' => (string) Str::uuid(), 'support_ticket_id' => $ticketId, 'type' => 'STATUS_CHANGED',
                'from_status' => $o->status, 'to_status' => $d['to_status'], 'actor_id' => $actor->id, 'message' => $d['message'], 'metadata' => '{}', 'occurred_at' => now()]);
            $this->audit->record('support.ticket.transitioned', 'support_ticket', $ticketId, ['from' => $o->status, 'to' => $d['to_status']]);

            return ['id' => $ticketId, 'status' => $d['to_status']];
        });
    }
}
