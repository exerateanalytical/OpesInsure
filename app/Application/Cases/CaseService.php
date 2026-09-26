<?php

declare(strict_types=1);

namespace App\Application\Cases;

use App\Application\Cases\Models\CaseDecision;
use App\Application\Cases\Models\CaseTask;
use App\Application\Cases\Models\CaseType;
use App\Application\Cases\Models\QueueMember;
use App\Application\Cases\Models\WorkCase;
use App\Application\Cases\Models\WorkQueue;
use App\Application\Cases\Sla\BusinessHoursCalendar;
use App\Application\Cases\Sla\SlaService;
use App\Domain\Shared\Clock\Clock;
use App\Domain\Shared\StateMachine\TransitionContext;
use App\Domain\Shared\StateMachine\TransitionDenied;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-CAS-001 CaseService (ICE §6.3): open (pins the type version, SLA clocks,
 * auto-tasks, routing), transition (StateMachineEngine), assign (INV-6.1 one
 * accountable owner, every change recorded), decide (append-only, reversal =
 * new decision), pull-next from a queue, tasks.
 *
 * Engine 3 authority checks on decisions are not wired yet (REQ-AUTH-002):
 * authority_grant_id stays NULL until that engine exists.
 */
final class CaseService
{
    public function __construct(
        private readonly CaseMachine $machine,
        private readonly CaseJournal $journal,
        private readonly SlaService $sla,
        private readonly QueueRouter $router,
        private readonly BusinessHoursCalendar $calendar,
        private readonly Clock $clock,
    ) {}

    /** Current EFFECTIVE version of a type (latest version valid today). */
    public function effectiveType(string $code): CaseType
    {
        $today = $this->clock->today()->toDateString();
        $type = CaseType::where('code', $code)->where('status', 'EFFECTIVE')
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today))
            ->orderByDesc('version')->first();

        return $type ?? throw CaseProblem::make('CASE_TYPE_NOT_EFFECTIVE', 422, "No effective case type {$code}.", ['case_type' => $code]);
    }

    /**
     * @param  array{title: string, priority?: string, confidentiality?: string, subject_type?: ?string, subject_id?: ?string,
     *   parent_case_id?: ?string, branch_id?: ?string, jurisdiction?: string, carrier_id?: ?string, idempotency_key?: ?string,
     *   source_type?: ?string, source_id?: ?string, owner_user_id?: ?string, queue_id?: ?string}  $attrs
     */
    public function open(string $tenantId, string $typeCode, array $attrs, ?User $actor): WorkCase
    {
        if (! empty($attrs['idempotency_key'])) {
            $existing = WorkCase::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('idempotency_key', $attrs['idempotency_key'])->first();
            if ($existing) {
                if ($existing->case_type_code !== $typeCode) {
                    throw CaseProblem::make('IDEMPOTENCY_KEY_REUSED', 409, 'The idempotency key was already used for a different case.');
                }

                return $existing;
            }
        }
        $type = $this->effectiveType($typeCode);
        $this->assertSubtype($type, $attrs['case_subtype'] ?? null);
        $confidentiality = $this->confidentiality($type->default_confidentiality, $attrs['confidentiality'] ?? null);

        return DB::transaction(function () use ($tenantId, $type, $attrs, $actor, $confidentiality) {
            $now = $this->clock->now();
            $routing = ['queue_id' => $attrs['queue_id'] ?? null, 'owner_user_id' => $attrs['owner_user_id'] ?? null, 'reason' => ['explicit assignment']];
            if ($routing['queue_id'] === null && $routing['owner_user_id'] === null) {
                $routing = $this->router->route($tenantId, $type->code, $attrs['branch_id'] ?? null);
            }
            if ($routing['owner_user_id']) {
                $this->assertMember($tenantId, $routing['owner_user_id']);
            }
            if ($routing['queue_id']) {
                $this->assertQueue($tenantId, $routing['queue_id']);
            }

            $case = WorkCase::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'carrier_id' => $attrs['carrier_id'] ?? null,
                'case_type_id' => $type->id, 'case_type_code' => $type->code,
                'case_number' => 'CASE-'.$now->format('Ym').'-'.strtoupper(Str::random(8)),
                'title' => $attrs['title'], 'status' => $type->initialState(), 'priority' => $attrs['priority'] ?? 'NORMAL',
                'confidentiality' => $confidentiality, 'subject_type' => $attrs['subject_type'] ?? null, 'subject_id' => $attrs['subject_id'] ?? null,
                'parent_case_id' => $attrs['parent_case_id'] ?? null, 'owner_user_id' => $routing['owner_user_id'], 'queue_id' => $routing['queue_id'],
                'branch_id' => $attrs['branch_id'] ?? null, 'jurisdiction' => $attrs['jurisdiction'] ?? 'CM',
                'source_type' => $attrs['source_type'] ?? null, 'source_id' => $attrs['source_id'] ?? null,
                // Owner decision #13: case_family + case_type + case_subtype + domain_reference.
                'case_family' => $type->family_code, 'case_subtype' => $attrs['case_subtype'] ?? null, 'product_id' => $attrs['product_id'] ?? null,
                'domain_reference' => $attrs['domain_reference'] ?? $this->domainReference($attrs),
                'opened_at' => $now, 'opened_by' => $actor?->id, 'idempotency_key' => $attrs['idempotency_key'] ?? null,
            ]);
            $this->journal->event($case, 'OPENED', ['case_type_version' => $type->version, 'confidentiality' => $confidentiality], null, $case->status, $actor?->id);
            $this->journal->event($case, 'ROUTED', ['queue_id' => $case->queue_id, 'owner_user_id' => $case->owner_user_id, 'reason' => $routing['reason']], null, null, $actor?->id);
            $this->sla->start($case, $type);
            $this->autoTasks($case, $type, ['event:opened', 'state:'.$case->status], $actor);
            $this->journal->audit('case.opened', $case, ['case_type_version' => $type->version, 'queue_id' => $case->queue_id, 'owner_user_id' => $case->owner_user_id]);
            $this->journal->publish('case.opened', 'case', $case->id, ['case_number' => $case->case_number, 'case_type' => $type->code, 'case_type_version' => $type->version, 'status' => $case->status, 'confidentiality' => $confidentiality]);
            if ($case->queue_id) {
                $this->journal->publish('queue.routed', 'case', $case->id, ['queue_id' => $case->queue_id, 'owner_user_id' => $case->owner_user_id, 'reason' => $routing['reason']]);
            }

            return $case->refresh();
        });
    }

    /**
     * Owner decisions #13 / #32: change a case's sub-type (e.g. a manual quote becomes COMPLEX or REFERRED);
     * running SLA clocks are re-targeted from the resolved policies. Audited, reason required.
     *
     * @return array{case: WorkCase, retargeted: list<array{metric: string, from: int, to: int}>}
     */
    public function reclassify(WorkCase $case, ?string $subtype, ?User $actor, string $reason): array
    {
        if (trim($reason) === '') {
            throw CaseProblem::make('REASON_REQUIRED', 422, 'A reason is required to change the case sub-type.');
        }

        return DB::transaction(function () use ($case, $subtype, $actor, $reason) {
            $case = $this->lock($case->id);
            if ($case->closed_at !== null) {
                throw CaseProblem::make('CASE_CLOSED', 409, 'A closed case cannot be reclassified.');
            }
            $type = CaseType::findOrFail($case->case_type_id);
            $this->assertSubtype($type, $subtype);
            $from = $case->case_subtype;
            if ($from === $subtype) {
                return ['case' => $case, 'retargeted' => []];
            }
            $case->update(['case_subtype' => $subtype, 'version' => $case->version + 1]);
            $this->journal->event($case, 'RECLASSIFIED', ['from' => $from, 'to' => $subtype, 'reason' => $reason], null, null, $actor?->id);
            $changed = $this->sla->retarget($case, $type);
            $this->journal->audit('case.reclassified', $case, ['from' => $from, 'to' => $subtype, 'retargeted' => $changed]);

            return ['case' => $case->refresh(), 'retargeted' => $changed];
        });
    }

    /** @param array<string, mixed> $payload */
    public function transition(WorkCase $case, string $event, ?User $actor, ?string $reason = null, array $payload = []): WorkCase
    {
        return DB::transaction(function () use ($case, $event, $actor, $reason, $payload) {
            $case = $this->lock($case->id);
            $type = CaseType::findOrFail($case->case_type_id); // pinned version (INV-6.2)
            $raw = CaseTypeCatalogue::rawTransition($type, $case->status, $event);
            if ($raw && ! empty($raw['requires_reason']) && trim((string) $reason) === '') {
                throw CaseProblem::make('REASON_REQUIRED', 422, "A reason is required for {$event}.", ['event' => $event]);
            }
            $from = $case->status;
            try {
                $result = $this->machine->applyCase($type, $event, $this->context($case, $from, $actor, $payload, $reason));
            } catch (TransitionDenied $e) {
                throw CaseProblem::fromDenied($e);
            }
            $to = $result->to;
            $terminal = $type->isTerminal($to);
            // Gap Closure Pack file 10: optional closure reason on the terminal transition (validated against the taxonomy).
            $closureReason = $terminal ? ($payload['closure_reason'] ?? null) : null;
            \App\Application\OperationsTaxonomy\OperationsCatalogue::assert('closure_reasons', $closureReason, 'closure_reason');
            $leftInitial = $from === $type->initialState() && $case->first_responded_at === null;
            $case->update([
                'status' => $to, 'version' => $case->version + 1,
                'first_responded_at' => $case->first_responded_at ?? ($leftInitial ? $this->clock->now() : null),
                'closed_at' => $terminal ? $this->clock->now() : null,
                'outcome' => $terminal ? ($payload['outcome'] ?? $to) : null,
                'closure_reason' => $closureReason,
            ]);
            $this->journal->event($case, 'TRANSITIONED', ['event' => $event, 'reason' => $reason] + $payload, $from, $to, $actor?->id);
            $this->sla->onTransition($case, $type, $from, $to, $leftInitial);
            if ($terminal) {
                $this->sla->stopAll($case);
                foreach (CaseTask::where('case_id', $case->id)->whereIn('status', CaseTask::OPEN_STATES)->get() as $t) {
                    $t->update(['status' => 'CANCELLED', 'completed_at' => $this->clock->now(), 'result' => ['reason' => 'CASE_'.$to]]);
                }
                $this->journal->publish('case.closed', 'case', $case->id, ['status' => $to, 'outcome' => $case->outcome]);
            }
            if ($from === 'RESOLVED' && ! $terminal) {
                $this->journal->publish('case.reopened', 'case', $case->id, ['status' => $to, 'reason' => $reason]);
            }
            $this->autoTasks($case, $type, ['event:'.$event, 'state:'.$to], $actor);
            $this->journal->audit('case.transitioned', $case, ['event' => $event, 'from' => $from, 'to' => $to], $reason, ['old' => ['status' => $from], 'new' => ['status' => $to]]);

            return $case->refresh();
        });
    }

    /** @return list<string> */
    public function availableEvents(WorkCase $case, ?User $actor): array
    {
        return $this->machine->availableCase(CaseType::findOrFail($case->case_type_id), $this->context($case, $case->status, $actor));
    }

    /** INV-6.1: exactly one accountable owner (user, else queue); every change is an event. */
    public function assign(WorkCase $case, ?string $userId, ?string $queueId, ?User $actor, ?string $reason = null): WorkCase
    {
        if ($userId === null && $queueId === null) {
            throw CaseProblem::make('OWNER_REQUIRED', 422, 'A case must keep an accountable owner: give a user or a queue.');
        }

        return DB::transaction(function () use ($case, $userId, $queueId, $actor, $reason) {
            $case = $this->lock($case->id);
            if ($case->closed_at !== null) {
                throw CaseProblem::make('CASE_CLOSED', 409, 'A closed case cannot be reassigned.');
            }
            if ($userId) {
                $this->assertMember($case->tenant_id, $userId);
            }
            if ($queueId) {
                $this->assertQueue($case->tenant_id, $queueId);
            }
            $old = ['owner_user_id' => $case->owner_user_id, 'queue_id' => $case->queue_id];
            $new = ['owner_user_id' => $userId, 'queue_id' => $queueId ?? $case->queue_id];
            $case->update($new + ['version' => $case->version + 1]);
            $this->journal->event($case, 'ASSIGNED', ['from' => $old, 'to' => $new, 'reason' => $reason], null, null, $actor?->id);
            $this->journal->audit('case.assigned', $case, [], $reason, ['old' => $old, 'new' => $new]);
            $this->journal->publish('case.assigned', 'case', $case->id, $new);

            return $case->refresh();
        });
    }

    /** Pull model: the oldest unowned open case of the queue goes to the calling member. */
    public function pullNext(WorkQueue $queue, User $actor): ?WorkCase
    {
        if (! QueueMember::where('queue_id', $queue->id)->where('user_id', $actor->id)->where('active', true)->exists()) {
            throw CaseProblem::make('NOT_QUEUE_MEMBER', 403, 'Only active queue members can pull work from this queue.');
        }

        return DB::transaction(function () use ($queue, $actor) {
            $id = WorkCase::query()->where('queue_id', $queue->id)->whereNull('owner_user_id')->whereNull('closed_at')
                ->orderByRaw("CASE priority WHEN 'CRITICAL' THEN -1 WHEN 'URGENT' THEN 0 WHEN 'HIGH' THEN 1 WHEN 'NORMAL' THEN 2 ELSE 3 END")
                ->orderByRaw('due_at NULLS LAST')->orderBy('opened_at')->lock('FOR UPDATE SKIP LOCKED')->value('id');
            if (! $id) {
                return null;
            }

            return $this->assign(WorkCase::withoutGlobalScopes()->findOrFail($id), $actor->id, $queue->id, $actor, 'PULLED_FROM_QUEUE');
        });
    }

    /**
     * Append-only decision (INV-6.4). A reversal is a new decision pointing at the one it reverses.
     *
     * @param  array{decision_type: string, outcome: string, rationale: string, conditions?: array, reverses_decision_id?: ?string}  $d
     */
    public function decide(WorkCase $case, array $d, User $actor): CaseDecision
    {
        return DB::transaction(function () use ($case, $d, $actor) {
            $case = $this->lock($case->id);
            if ($case->closed_at !== null) {
                throw CaseProblem::make('CASE_CLOSED', 409, 'A closed case cannot take new decisions.');
            }
            if (! empty($d['reverses_decision_id'])) {
                $target = CaseDecision::where('case_id', $case->id)->whereKey($d['reverses_decision_id'])->first();
                if (! $target || CaseDecision::where('reverses_decision_id', $target->id)->exists()) {
                    throw CaseProblem::make('DECISION_NOT_REVERSIBLE', 422, 'The decision does not belong to this case or is already reversed.');
                }
            }
            $decision = CaseDecision::create([
                'case_id' => $case->id, 'decision_type' => $d['decision_type'], 'outcome' => $d['outcome'], 'rationale' => $d['rationale'],
                'conditions' => $d['conditions'] ?? [], 'decided_by' => $actor->id, 'authority_grant_id' => null,
                'reverses_decision_id' => $d['reverses_decision_id'] ?? null, 'decided_at' => $this->clock->now(),
            ]);
            $this->journal->event($case, empty($d['reverses_decision_id']) ? 'DECIDED' : 'DECISION_REVERSED', ['decision_id' => $decision->id, 'decision_type' => $decision->decision_type, 'outcome' => $decision->outcome], null, null, $actor->id);
            $this->journal->audit('case.decided', $case, ['decision_id' => $decision->id, 'outcome' => $decision->outcome, 'reverses' => $decision->reverses_decision_id], $d['rationale']);
            $this->journal->publish('case.decided', 'case', $case->id, ['decision_id' => $decision->id, 'decision_type' => $decision->decision_type, 'outcome' => $decision->outcome, 'reverses_decision_id' => $decision->reverses_decision_id]);

            return $decision;
        });
    }

    /** @param array{title: string, template_code?: ?string, assignee_user_id?: ?string, queue_id?: ?string, due_at?: ?string, due_in_business_minutes?: ?int} $d */
    public function addTask(WorkCase $case, array $d, ?User $actor): CaseTask
    {
        return DB::transaction(function () use ($case, $d, $actor) {
            $case = $this->lock($case->id);
            if ($case->closed_at !== null) {
                throw CaseProblem::make('CASE_CLOSED', 409, 'Tasks cannot be added to a closed case.');
            }

            return $this->createTask($case, $d, $actor);
        });
    }

    /** @param array<string, mixed>|null $result */
    public function transitionTask(CaseTask $task, string $to, ?User $actor, ?array $result = null): CaseTask
    {
        return DB::transaction(function () use ($task, $to, $actor, $result) {
            $case = $this->lock($task->case_id);
            $task = CaseTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $from = $task->status;
            try {
                $this->machine->applyTaskTo($to, new TransitionContext('case_task', $task->id, $from, $actor, null, ['case_id' => $case->id]));
            } catch (TransitionDenied $e) {
                throw CaseProblem::fromDenied($e);
            }
            $done = in_array($to, ['DONE', 'CANCELLED'], true);
            $task->update(['status' => $to, 'completed_at' => $done ? $this->clock->now() : null, 'completed_by' => $done ? $actor?->id : null, 'result' => $result ?? $task->result]);
            $this->journal->event($case, 'TASK_'.$to, ['task_id' => $task->id, 'from' => $from], null, null, $actor?->id);

            return $task->refresh();
        });
    }

    public function lock(string $id): WorkCase
    {
        return WorkCase::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $d */
    private function createTask(WorkCase $case, array $d, ?User $actor): CaseTask
    {
        if (! empty($d['assignee_user_id'])) {
            $this->assertMember($case->tenant_id, $d['assignee_user_id']);
        }
        $due = $d['due_at'] ?? null;
        if ($due === null && ! empty($d['due_in_business_minutes'])) {
            $due = $this->calendar->addBusinessMinutes($this->clock->now(), (int) $d['due_in_business_minutes'], $case->jurisdiction, $case->branch_id, $this->calendar->timezone($case->branch_id, $case->tenant_id));
        }
        \App\Application\OperationsTaxonomy\OperationsCatalogue::assert('task_types', $d['task_type'] ?? null, 'task_type');
        $task = CaseTask::create([
            'case_id' => $case->id, 'template_code' => $d['template_code'] ?? null, 'title' => $d['title'], 'status' => 'OPEN', 'task_type' => $d['task_type'] ?? null,
            'assignee_user_id' => $d['assignee_user_id'] ?? null, 'queue_id' => $d['queue_id'] ?? ($d['assignee_user_id'] ?? null ? null : $case->queue_id),
            'due_at' => $due, 'created_by' => $actor?->id,
        ]);
        $this->journal->event($case, 'TASK_CREATED', ['task_id' => $task->id, 'title' => $task->title, 'template_code' => $task->template_code], null, null, $actor?->id);
        $this->journal->publish('case.task.created', 'case_task', $task->id, ['case_id' => $case->id, 'assignee_user_id' => $task->assignee_user_id, 'due_at' => $task->due_at?->toIso8601String()]);

        return $task;
    }

    /** @param list<string> $triggers */
    private function autoTasks(WorkCase $case, CaseType $type, array $triggers, ?User $actor): void
    {
        foreach ($type->auto_tasks ?? [] as $t) {
            if (! in_array($t['on'] ?? '', $triggers, true)) {
                continue;
            }
            $this->createTask($case, [
                'title' => $t['title'], 'template_code' => $t['task_template_code'] ?? null,
                'assignee_user_id' => ($t['assignee_rule'] ?? null) === 'case_owner' ? $case->owner_user_id : null,
                'due_in_business_minutes' => $t['due_offset_business_minutes'] ?? null,
            ], $actor);
        }
    }

    private function context(WorkCase $case, string $state, ?User $actor, array $payload = [], ?string $reason = null): TransitionContext
    {
        $role = $actor ? DB::table('tenant_memberships')->where('user_id', $actor->id)->where('tenant_id', $case->tenant_id)->where('status', 'ACTIVE')->value('role_code') : 'SYSTEM';

        return new TransitionContext('case', $case->id, $state, $actor, $role, $payload, $reason, $case);
    }

    private function confidentiality(string $default, ?string $requested): string
    {
        $rank = array_flip(CaseVisibility::LEVELS);
        if ($requested === null) {
            return $default;
        }
        if (! isset($rank[$requested]) || $rank[$requested] < $rank[$default]) {
            throw CaseProblem::make('CONFIDENTIALITY_TOO_LOW', 422, "Confidentiality cannot be lower than the case type default ({$default}).");
        }

        return $requested;
    }

    private function assertMember(string $tenantId, string $userId): void
    {
        $ok = DB::table('tenant_memberships')->join('users', 'users.id', '=', 'tenant_memberships.user_id')
            ->where('tenant_memberships.tenant_id', $tenantId)->where('tenant_memberships.user_id', $userId)
            ->where('tenant_memberships.status', 'ACTIVE')->where('users.status', 'ACTIVE')->exists();
        if (! $ok) {
            throw CaseProblem::make('ASSIGNEE_NOT_IN_TENANT', 422, 'The assignee must be an active member of the case tenant.');
        }
    }

    private function assertQueue(string $tenantId, string $queueId): void
    {
        if (! WorkQueue::whereKey($queueId)->where('tenant_id', $tenantId)->where('active', true)->exists()) {
            throw CaseProblem::make('QUEUE_NOT_FOUND', 422, 'The queue is not an active queue of the case tenant.');
        }
    }

    private function assertSubtype(CaseType $type, ?string $subtype): void
    {
        $allowed = $type->subtypes ?? [];
        if ($subtype !== null && $allowed !== [] && ! in_array($subtype, $allowed, true)) {
            throw CaseProblem::make('CASE_SUBTYPE_INVALID', 422, "Sub-type {$subtype} is not defined for {$type->code}.", ['allowed' => $allowed]);
        }
    }

    /** @param array<string, mixed> $attrs */
    private function domainReference(array $attrs): ?string
    {
        foreach ([['source_type', 'source_id'], ['subject_type', 'subject_id']] as [$t, $id]) {
            if (! empty($attrs[$t]) && ! empty($attrs[$id])) {
                return $attrs[$t].':'.$attrs[$id];
            }
        }

        return null;
    }
}
