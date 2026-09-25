<?php

declare(strict_types=1);

namespace App\Application\Cases;

use App\Application\Cases\Models\CaseTask;
use App\Application\Cases\Models\QueueMember;
use App\Application\Cases\Models\WorkQueue;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CAS-001 QueueRouter (ICE §6.3): explainable routing of a new case to a
 * queue and, for LEAST_LOADED / ROUND_ROBIN queues, to a member. PULL queues
 * leave the case unowned for /queues/{id}/next.
 */
final class QueueRouter
{
    /** @return array{queue_id: ?string, owner_user_id: ?string, reason: list<string>} */
    public function route(string $tenantId, string $caseTypeCode, ?string $branchId): array
    {
        $why = [];
        $candidates = WorkQueue::where('tenant_id', $tenantId)->where('active', true)
            ->whereJsonContains('case_type_codes', $caseTypeCode)->get();
        $queue = $branchId ? $candidates->firstWhere('branch_id', $branchId) : null;
        if ($queue) {
            $why[] = "queue {$queue->code} handles {$caseTypeCode} for the case branch";
        } elseif ($queue = $candidates->firstWhere('branch_id', null) ?? $candidates->first()) {
            $why[] = "queue {$queue->code} handles {$caseTypeCode}";
        } elseif ($queue = WorkQueue::where('tenant_id', $tenantId)->where('active', true)->where('is_default', true)->first()) {
            $why[] = "no queue lists {$caseTypeCode}; tenant default queue {$queue->code}";
        } else {
            return ['queue_id' => null, 'owner_user_id' => null, 'reason' => ["no active queue for {$caseTypeCode}; case stays with the opener's tenant unassigned"]];
        }

        $owner = null;
        if (in_array($queue->routing_rule, ['LEAST_LOADED', 'ROUND_ROBIN'], true)) {
            $owner = $this->pickMember($queue, $why);
        } else {
            $why[] = 'PULL queue: a member takes the case with /queues/{id}/next';
        }

        return ['queue_id' => $queue->id, 'owner_user_id' => $owner, 'reason' => $why];
    }

    /** @param list<string> $why */
    private function pickMember(WorkQueue $queue, array &$why): ?string
    {
        $members = QueueMember::where('queue_id', $queue->id)->where('active', true)
            ->whereIn('user_id', DB::table('users')->where('status', 'ACTIVE')->select('id'))->get();
        $best = null;
        foreach ($members as $m) {
            $load = DB::table('cases')->where('owner_user_id', $m->user_id)->whereNull('closed_at')->count()
                + CaseTask::where('assignee_user_id', $m->user_id)->whereIn('status', CaseTask::OPEN_STATES)->count();
            if ($load >= $m->capacity) {
                continue;
            }
            $key = $queue->routing_rule === 'LEAST_LOADED'
                ? [$load, $m->last_assigned_at?->getTimestamp() ?? 0]
                : [$m->last_assigned_at?->getTimestamp() ?? 0, $load];
            if ($best === null || $key < $best[0]) {
                $best = [$key, $m, $load];
            }
        }
        if ($best === null) {
            $why[] = 'every member is at capacity or inactive; left unowned in the queue';

            return null;
        }
        [$key, $m, $load] = $best;
        $m->update(['last_assigned_at' => now()]);
        $why[] = "{$queue->routing_rule}: member {$m->user_id} (open load {$load}/{$m->capacity})";

        return $m->user_id;
    }
}
