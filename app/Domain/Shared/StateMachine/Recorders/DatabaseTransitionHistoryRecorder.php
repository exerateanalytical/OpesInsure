<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine\Recorders;

use App\Domain\Shared\StateMachine\Contracts\TransitionHistoryRecorder;
use App\Domain\Shared\StateMachine\TransitionResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Append-only generic history (workflow_transition_history).
 * Domain-specific history tables (policy_status_history, proposal_status_history, ...) stay until their wave migrates.
 */
final class DatabaseTransitionHistoryRecorder implements TransitionHistoryRecorder
{
    public function record(TransitionResult $r): void
    {
        DB::table('workflow_transition_history')->insert([
            'id' => (string) Str::uuid(),
            'machine' => $r->machine,
            'machine_version' => $r->machineVersion,
            'subject_type' => $r->context->subjectType,
            'subject_id' => $r->context->subjectId,
            'event' => $r->transition->event,
            'from_state' => $r->from,
            'to_state' => $r->to,
            'actor_id' => $r->context->actorId(),
            'actor_role' => $r->context->actorRole,
            'reason' => $r->context->reason,
            'domain_event' => $r->transition->domainEvent,
            'payload' => json_encode($r->context->payload, JSON_THROW_ON_ERROR),
            'occurred_at' => $r->occurredAt->format('Y-m-d H:i:s.uP'),
        ]);
    }
}
