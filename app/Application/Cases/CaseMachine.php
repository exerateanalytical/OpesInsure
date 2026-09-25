<?php

declare(strict_types=1);

namespace App\Application\Cases;

use App\Application\Cases\Models\CaseType;
use App\Domain\Shared\StateMachine\Contracts\PermissionChecker;
use App\Domain\Shared\StateMachine\Contracts\TransitionEventPublisher;
use App\Domain\Shared\StateMachine\Contracts\TransitionHistoryRecorder;
use App\Domain\Shared\StateMachine\GuardResult;
use App\Domain\Shared\StateMachine\StateMachineDefinition;
use App\Domain\Shared\StateMachine\StateMachineEngine;
use App\Domain\Shared\StateMachine\TransitionContext;
use App\Domain\Shared\StateMachine\TransitionResult;
use Illuminate\Support\Facades\DB;

/**
 * Case + task states run on the Batch 1 StateMachineEngine (REQ-WFL-001):
 * history → workflow_transition_history, domain event → outbox.
 * Permissions on case-type transitions use User::hasPermission (tenant roles).
 */
final class CaseMachine
{
    private readonly StateMachineEngine $engine;

    private static ?StateMachineDefinition $task = null;

    public function __construct(TransitionHistoryRecorder $history, TransitionEventPublisher $events)
    {
        $permissions = new class implements PermissionChecker {
            public function allows(string $permission, TransitionContext $context): bool
            {
                $a = $context->actor;

                return is_object($a) && method_exists($a, 'hasPermission') && $a->hasPermission($permission);
            }
        };
        $this->engine = new StateMachineEngine($history, $events, $permissions);
        $this->engine->registerGuard(CaseTypeCatalogue::DECISION_GUARD, function ($t, TransitionContext $c) {
            $ok = DB::table('case_decisions as d')->where('d.case_id', $c->subjectId)->whereNull('d.reverses_decision_id')
                ->whereNotExists(fn ($q) => $q->from('case_decisions as r')->whereColumn('r.reverses_decision_id', 'd.id'))->exists();

            return $ok ? GuardResult::pass() : GuardResult::fail('A decision must be recorded before this transition.');
        });
        // Batch 12C bridge: complaint facts (REQ-CPL-001) gate COMPLAINT v2 transitions on every path.
        \App\Application\Complaints\ComplaintLifecycle::registerGuards($this->engine);
    }

    public function applyCase(CaseType $type, string $event, TransitionContext $ctx): TransitionResult
    {
        return $this->engine->apply(CaseTypeCatalogue::machine($type), $event, $ctx);
    }

    /** @return list<string> events available to this actor from the context state */
    public function availableCase(CaseType $type, TransitionContext $ctx): array
    {
        return array_map(fn ($t) => $t->event, $this->engine->available(CaseTypeCatalogue::machine($type), $ctx));
    }

    public function applyTaskTo(string $to, TransitionContext $ctx): TransitionResult
    {
        return $this->engine->applyTo(self::taskMachine(), $to, $ctx);
    }

    public static function taskMachine(): StateMachineDefinition
    {
        return self::$task ??= StateMachineDefinition::fromArray([
            'name' => 'case_task', 'version' => 1, 'subject_type' => 'case_task',
            'states' => ['OPEN' => ['initial' => true], 'IN_PROGRESS' => [], 'BLOCKED' => [], 'DONE' => ['terminal' => true], 'CANCELLED' => ['terminal' => true]],
            'transitions' => [
                ['event' => 'start', 'from' => ['OPEN'], 'to' => 'IN_PROGRESS', 'domain_event' => 'case.task.started'],
                ['event' => 'block', 'from' => ['OPEN', 'IN_PROGRESS'], 'to' => 'BLOCKED', 'domain_event' => 'case.task.blocked'],
                ['event' => 'unblock', 'from' => ['BLOCKED'], 'to' => 'IN_PROGRESS', 'domain_event' => 'case.task.unblocked'],
                ['event' => 'complete', 'from' => ['OPEN', 'IN_PROGRESS'], 'to' => 'DONE', 'domain_event' => 'case.task.completed'],
                ['event' => 'cancel', 'from' => ['OPEN', 'IN_PROGRESS', 'BLOCKED'], 'to' => 'CANCELLED', 'domain_event' => 'case.task.cancelled'],
            ],
        ]);
    }
}
