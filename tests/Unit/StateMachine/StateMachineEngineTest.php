<?php

// REQ-WFL-001, REQ-DUP-006

use App\Domain\Claims\ClaimLifecycle;
use App\Domain\Claims\ClaimStateMachine;
use Tests\Support\ClaimMachineAdapter;
use App\Domain\Shared\StateMachine\Contracts\AuthorityHook;
use App\Domain\Shared\StateMachine\Contracts\PermissionChecker;
use App\Domain\Shared\StateMachine\Contracts\TransitionEventPublisher;
use App\Domain\Shared\StateMachine\GuardResult;
use App\Domain\Shared\StateMachine\Recorders\InMemoryTransitionHistoryRecorder;
use App\Domain\Shared\StateMachine\StateMachineDefinition;
use App\Domain\Shared\StateMachine\StateMachineEngine;
use App\Domain\Shared\StateMachine\TransitionContext;
use App\Domain\Shared\StateMachine\TransitionDefinition;
use App\Domain\Shared\StateMachine\TransitionDenied;
use App\Domain\Shared\StateMachine\TransitionResult;

function wflSample(): StateMachineDefinition
{
    return StateMachineDefinition::fromArray([
        'name' => 'sample', 'version' => 2, 'subject_type' => 'thing',
        'states' => ['DRAFT' => ['initial' => true], 'SUBMITTED' => [], 'APPROVED' => [], 'CLOSED' => ['terminal' => true]],
        'transitions' => [
            ['event' => 'submit', 'from' => ['DRAFT'], 'to' => 'SUBMITTED', 'actors' => ['customer'], 'guards' => ['complete'],
                'domain_event' => 'sample.submitted', 'notification' => 'sample.submitted', 'failure_path' => 'stay DRAFT'],
            ['event' => 'approve', 'from' => ['SUBMITTED'], 'to' => 'APPROVED', 'permission' => 'sample.approve', 'authority' => 'limit'],
            ['event' => 'close', 'from' => ['SUBMITTED', 'APPROVED'], 'to' => 'CLOSED', 'audit' => false],
        ],
    ]);
}

function wflCtx(string $state, ?string $role = null, array $payload = []): TransitionContext
{
    return new TransitionContext('thing', 't-1', $state, 'user-9', $role, $payload, 'because');
}

test('REQ-WFL-001 definition keeps all Part II columns and round-trips', function () {
    $m = wflSample();
    expect($m->initialState())->toBe('DRAFT')
        ->and($m->states()['CLOSED']->terminal)->toBeTrue()
        ->and($m->transitionFor('DRAFT', 'submit')->failurePath)->toBe('stay DRAFT')
        ->and($m->adjacency())->toBe(['DRAFT' => ['SUBMITTED'], 'SUBMITTED' => ['APPROVED', 'CLOSED'], 'APPROVED' => ['CLOSED']]);
    expect(StateMachineDefinition::fromArray($m->toArray())->toArray())->toBe($m->toArray());
});

test('REQ-WFL-001 definition validation rejects bad machines', function (array $bad) {
    StateMachineDefinition::fromArray($bad);
})->throws(InvalidArgumentException::class)->with([
    'unknown state' => [['name' => 'x', 'states' => ['A'], 'transitions' => [['event' => 'e', 'from' => ['A'], 'to' => 'B']]]],
    'leaves terminal' => [['name' => 'x', 'states' => ['A' => ['terminal' => true], 'B' => []], 'transitions' => [['event' => 'e', 'from' => ['A'], 'to' => 'B']]]],
    'ambiguous event' => [['name' => 'x', 'states' => ['A', 'B', 'C'], 'transitions' => [['event' => 'e', 'from' => ['A'], 'to' => 'B'], ['event' => 'e', 'from' => ['A'], 'to' => 'C']]]],
    'two initials' => [['name' => 'x', 'states' => ['A' => ['initial' => true], 'B' => ['initial' => true]], 'transitions' => []]],
]);

test('REQ-WFL-001 invalid transition, actor and guard are enforced in order', function () {
    $e = (new StateMachineEngine())->registerGuard('complete', fn ($t, TransitionContext $c) => ($c->payload['complete'] ?? false)
        ? GuardResult::pass() : GuardResult::fail('Evidence incomplete.'));
    $m = wflSample();

    $stage = fn (callable $fn) => (function () use ($fn) { try { $fn(); } catch (TransitionDenied $x) { return [$x->stage, $x->failurePath]; } return null; })();

    expect($stage(fn () => $e->apply($m, 'approve', wflCtx('DRAFT', 'customer'))))->toBe([TransitionDenied::INVALID, null])
        ->and($stage(fn () => $e->apply($m, 'submit', wflCtx('DRAFT', 'agent', ['complete' => true]))))->toBe([TransitionDenied::ACTOR, 'stay DRAFT'])
        ->and($stage(fn () => $e->apply($m, 'submit', wflCtx('DRAFT', 'customer'))))->toBe([TransitionDenied::GUARD, 'stay DRAFT']);

    $r = $e->apply($m, 'submit', wflCtx('DRAFT', 'customer', ['complete' => true]));
    expect($r->from)->toBe('DRAFT')->and($r->to)->toBe('SUBMITTED')->and($r->machineVersion)->toBe(2);
});

test('REQ-WFL-001 unregistered guard fails closed', function () {
    (new StateMachineEngine())->apply(wflSample(), 'submit', wflCtx('DRAFT', 'customer'));
})->throws(TransitionDenied::class, 'Guard complete is not registered.');

test('REQ-WFL-001 permission and authority hook gate the transition', function () {
    $perm = new class implements PermissionChecker {
        public bool $allow = false;
        public function allows(string $permission, TransitionContext $context): bool { return $this->allow && $permission === 'sample.approve'; }
    };
    $authority = new class implements AuthorityHook {
        public function authorize(string $key, TransitionDefinition $t, TransitionContext $c): GuardResult
        {
            return ($c->payload['amount'] ?? 0) <= 1000 ? GuardResult::pass() : GuardResult::fail("Amount exceeds {$key} authority.");
        }
    };
    $m = wflSample();

    // no permission checker configured => fail closed
    expect((new StateMachineEngine())->can($m, 'approve', wflCtx('SUBMITTED')))->toBeFalse();

    $e = new StateMachineEngine(null, null, $perm);
    expect($e->can($m, 'approve', wflCtx('SUBMITTED', null, ['amount' => 10])))->toBeFalse(); // permission denied
    $perm->allow = true;
    expect($e->can($m, 'approve', wflCtx('SUBMITTED', null, ['amount' => 10])))->toBeFalse(); // no authority hook => fail closed
    $e->registerAuthority('limit', $authority);
    expect($e->can($m, 'approve', wflCtx('SUBMITTED', null, ['amount' => 5000])))->toBeFalse()
        ->and($e->can($m, 'approve', wflCtx('SUBMITTED', null, ['amount' => 500])))->toBeTrue()
        ->and(array_map(fn ($t) => $t->event, $e->available($m, wflCtx('SUBMITTED', null, ['amount' => 500]))))->toBe(['approve', 'close']);
});

test('REQ-WFL-001 history and domain events are emitted on apply', function () {
    $history = new InMemoryTransitionHistoryRecorder();
    $events = new class implements TransitionEventPublisher {
        public array $published = [];
        public function publish(TransitionResult $r): void { $this->published[] = $r->transition->domainEvent ?? 'workflow.transition.applied'; }
    };
    $e = (new StateMachineEngine($history, $events))->registerGuard('complete', fn () => true);
    $m = wflSample();
    $e->apply($m, 'submit', wflCtx('DRAFT', 'customer'));
    $e->apply($m, 'close', wflCtx('SUBMITTED'));

    expect($history->records)->toHaveCount(1) // close has audit=false
        ->and($history->records[0]->context->actorId())->toBe('user-9')
        ->and($events->published)->toBe(['sample.submitted', 'workflow.transition.applied']);
});

test('REQ-DUP-006 both legacy claim definitions run on the engine with identical decisions', function (string $class, string $const, callable $factory) {
    $table = (new ReflectionClassConstant($class, $const))->getValue();
    $legacy = new $class();
    $machine = $factory();
    $engine = new StateMachineEngine();
    $states = array_keys($machine->states());

    foreach ($states as $from) {
        foreach ($states as $to) {
            $legacyOk = true;
            try { $legacy->assert($from, $to); } catch (DomainException) { $legacyOk = false; }
            $engineOk = true;
            try { $engine->applyTo($machine, $to, new TransitionContext('claim', 'c-1', $from)); } catch (TransitionDenied) { $engineOk = false; }
            expect($engineOk)->toBe($legacyOk, "{$class} {$from}->{$to}");
        }
    }
    $norm = function (array $adj) { ksort($adj); foreach ($adj as &$tos) { sort($tos); } return $adj; };
    expect($norm($machine->adjacency()))->toBe($norm(array_filter($table)));
})->with([
    'ClaimStateMachine' => [ClaimStateMachine::class, 'T', fn () => ClaimMachineAdapter::fromClaimStateMachine()],
    'ClaimLifecycle' => [ClaimLifecycle::class, 'TRANSITIONS', fn () => ClaimMachineAdapter::fromClaimLifecycle()],
]);

test('REQ-DUP-006 adapter surfaces the divergence between the two claim definitions', function () {
    $a = ClaimMachineAdapter::fromClaimStateMachine();
    $b = ClaimMachineAdapter::fromClaimLifecycle();
    expect($a->hasState('PAYMENT_PENDING'))->toBeFalse()->and($b->hasState('PAYMENT_PENDING'))->toBeTrue()
        ->and($a->states()['CLOSED']->terminal)->toBeTrue()->and($b->states()['CLOSED']->terminal)->toBeFalse();
});
