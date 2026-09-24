<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine;

use App\Application\Events\OutboxWriter;
use App\Domain\Shared\StateMachine\Contracts\TransitionEventPublisher;

/** Writes the transition domain event (or workflow.transition.applied) to the transactional outbox (REQ-ARC-003). */
final class OutboxTransitionEventPublisher implements TransitionEventPublisher
{
    public function __construct(private readonly OutboxWriter $outbox) {}

    public function publish(TransitionResult $r): void
    {
        $this->outbox->record(
            $r->transition->domainEvent ?? 'workflow.transition.applied',
            $r->context->subjectType,
            $r->context->subjectId,
            [
                'machine' => $r->machine,
                'event' => $r->transition->event,
                'from' => $r->from,
                'to' => $r->to,
                'actor_id' => $r->context->actorId(),
                'reason' => $r->context->reason,
            ],
            ['machine_version' => $r->machineVersion],
        );
    }
}
