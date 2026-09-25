<?php

declare(strict_types=1);

namespace App\Application\Cases\Bridges;

use App\Application\Cases\Models\WorkCase;
use App\Application\Cases\Models\WorkQueue;
use App\Domain\Shared\Clock\Clock;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CAS-002 operational queues: one board per active queue over the canonical cases, plus the legacy
 * work items that are still not bridged (REQ-DUP-022 projection counts), so supervisors see one backlog.
 * Counts honour the confidentiality scope of WorkCase (a user never learns about cases they cannot see).
 */
final class OperationalQueueBoard
{
    public function __construct(private readonly Clock $clock) {}

    /** @return array{queues: list<array<string, mixed>>, unqueued: array<string, int>, legacy_unbridged: array<string, int>} */
    public function for(string $tenantId): array
    {
        $now = $this->clock->now();
        $open = fn () => WorkCase::query()->where('tenant_id', $tenantId)->whereNull('closed_at');
        $clock = fn ($q, string $col) => $q->whereExists(fn ($s) => $s->from('sla_clocks')->whereColumn('sla_clocks.case_id', 'cases.id')->whereNull('sla_clocks.stopped_at')->whereNotNull('sla_clocks.'.$col));

        $queues = [];
        foreach (WorkQueue::where('tenant_id', $tenantId)->where('active', true)->orderBy('code')->get() as $q) {
            $queues[] = [
                'queue_id' => $q->id, 'code' => $q->code, 'name' => $q->name,
                'open' => $open()->where('queue_id', $q->id)->count(),
                'unassigned' => $open()->where('queue_id', $q->id)->whereNull('owner_user_id')->count(),
                'at_risk' => $clock($open()->where('queue_id', $q->id), 'warned_at')->count(),
                'breached' => $clock($open()->where('queue_id', $q->id), 'breached_at')->count(),
                'overdue' => $open()->where('queue_id', $q->id)->where('due_at', '<', $now)->count(),
                'by_case_type' => $open()->where('queue_id', $q->id)->groupBy('case_type_code')->selectRaw('case_type_code, count(*) as n')->pluck('n', 'case_type_code'),
                'members' => DB::table('queue_members')->where('queue_id', $q->id)->where('active', true)->count(),
            ];
        }

        return [
            'queues' => $queues,
            'unqueued' => ['open' => $open()->whereNull('queue_id')->count(), 'unassigned' => $open()->whereNull('queue_id')->whereNull('owner_user_id')->count()],
            'legacy_unbridged' => [
                'underwriting_cases' => DB::table('underwriting_cases')->where('tenant_id', $tenantId)->whereIn('status', ['QUEUED', 'IN_REVIEW', 'AWAITING_INFORMATION'])->whereNull('case_id')->count(),
                'compliance_cases' => DB::table('compliance_cases')->where('tenant_id', $tenantId)->where('status', '!=', 'CLOSED')->whereNull('case_id')->count(),
                'support_tickets_complaints' => DB::table('support_tickets')->where('tenant_id', $tenantId)->whereIn('type', ['COMPLAINT', 'REGULATORY_COMPLAINT'])->whereNotIn('status', ['RESOLVED', 'CLOSED', 'CANCELLED'])->whereNull('case_id')->count(),
                'renewal_work_items' => DB::table('renewal_work_items')->where('tenant_id', $tenantId)->whereNull('outcome')->whereNull('case_id')->count(),
            ],
        ];
    }
}
