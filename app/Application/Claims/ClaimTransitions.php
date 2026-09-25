<?php

declare(strict_types=1);

namespace App\Application\Claims;

use App\Domain\Claims\ClaimMachine;
use App\Domain\Claims\ClaimTransitionBlocked;
use App\Domain\Claims\ClaimTransitionGuard;
use App\Domain\Shared\StateMachine\Contracts\TransitionHistoryRecorder;
use App\Domain\Shared\StateMachine\StateMachineEngine;
use App\Domain\Shared\StateMachine\TransitionContext;
use App\Domain\Shared\StateMachine\TransitionDefinition;
use App\Models\Claim;
use DomainException;
use Illuminate\Contracts\Container\Container;

/**
 * REQ-CLM-001: the single gate every claim status change passes through (ClaimLifecycleService, ClaimController).
 * resolve (ClaimMachine) → tagged 'claims.transition_guards' → shared engine (workflow_transition_history).
 * The caller persists claims.status, claim_events, audit and outbox inside its own DB transaction (REQ-ARC-002);
 * the engine is built without an outbox publisher so the existing claim.* outbox events are not duplicated.
 */
final class ClaimTransitions
{
    public const GUARD_TAG = 'claims.transition_guards';

    private ?StateMachineEngine $engine = null;

    public function __construct(private readonly Container $app) {}

    /** Resolves the transition or throws DomainException (same message as the legacy definitions). */
    public function resolve(string $from, string $to): TransitionDefinition
    {
        return ClaimMachine::transitionTo($from, $to)
            ?? throw new DomainException('Invalid claim transition from '.$from.' to '.ClaimMachine::storedStatus($to).'.');
    }

    /** Runs every tagged guard interested in $event; throws ClaimTransitionBlocked on the first blocking reason. */
    public function guard(Claim $claim, string $event, array $context): void
    {
        foreach ($this->app->tagged(self::GUARD_TAG) as $guard) {
            if (! $guard instanceof ClaimTransitionGuard) {
                continue;
            }
            $events = $guard->events();
            if (! in_array('*', $events, true) && ! in_array($event, $events, true)) {
                continue;
            }
            $reason = $guard->check($claim, $event, $context);
            if ($reason !== null) {
                throw ClaimTransitionBlocked::by($guard::class, $event, $reason);
            }
        }
    }

    /** Validates + guards + records machine history. Returns the transition; the caller writes the status. */
    public function apply(Claim $claim, string $to, mixed $actor = null, ?string $reason = null, array $details = []): TransitionDefinition
    {
        $from = (string) $claim->status;
        $t = $this->resolve($from, $to);
        $actorId = is_object($actor) && method_exists($actor, 'getAuthIdentifier') ? (string) $actor->getAuthIdentifier() : null;
        // Guard context contract: 'from'/'to' = BLUEPRINT states, 'from_status'/'to_status' = stored codes, plus caller details.
        $this->guard($claim, $t->event, $details + [
            'from' => ClaimMachine::blueprintState($from), 'to' => ClaimMachine::blueprintState($t->to),
            'from_status' => $from, 'to_status' => $t->to, 'actor_id' => $actorId, 'reason' => $reason, 'reason_code' => $reason, 'details' => $details,
        ]);
        $this->engine()->apply(ClaimMachine::definition(), $t->event, new TransitionContext(
            ClaimMachine::SUBJECT, (string) $claim->id, $from, $actor, $actor ? 'staff' : 'system',
            ['blueprint_from' => ClaimMachine::blueprintState($from), 'blueprint_to' => ClaimMachine::blueprintState($t->to)], $reason, $claim,
        ));

        return $t;
    }

    private function engine(): StateMachineEngine
    {
        return $this->engine ??= new StateMachineEngine($this->app->make(TransitionHistoryRecorder::class));
    }
}
