<?php

// REQ-WFL-001, REQ-ARC-004

use App\Domain\Shared\StateMachine\Adapters\ClaimMachineAdapter;
use App\Domain\Shared\StateMachine\StateMachineEngine;
use App\Domain\Shared\StateMachine\StateMachineRegistry;
use App\Domain\Shared\StateMachine\TransitionContext;
use App\Providers\StateMachineServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->app->register(StateMachineServiceProvider::class));

test('REQ-WFL-001 applied transitions persist history and write the outbox event', function () {
    $id = (string) Str::uuid();
    $engine = app(StateMachineEngine::class);
    $machine = app(StateMachineRegistry::class)->get(ClaimMachineAdapter::LIFECYCLE);

    DB::transaction(fn () => $engine->applyTo($machine, 'SUBMITTED', new TransitionContext('claim', $id, 'DRAFT', null, 'customer', ['x' => 1], 'filed')));

    $row = DB::table('workflow_transition_history')->where('subject_id', $id)->first();
    expect($row->machine)->toBe(ClaimMachineAdapter::LIFECYCLE)
        ->and($row->from_state)->toBe('DRAFT')->and($row->to_state)->toBe('SUBMITTED')
        ->and($row->event)->toBe('to_submitted')->and($row->reason)->toBe('filed');

    $out = DB::table('outbox_messages')->where('aggregate_id', $id)->first();
    expect($out->event_name)->toBe('workflow.transition.applied')->and($out->aggregate_type)->toBe('claim');
});

test('REQ-WFL-001 rejected transitions write nothing', function () {
    $id = (string) Str::uuid();
    $engine = app(StateMachineEngine::class);
    $machine = app(StateMachineRegistry::class)->get(ClaimMachineAdapter::STATE_MACHINE);
    expect(fn () => $engine->applyTo($machine, 'PAID', new TransitionContext('claim', $id, 'DRAFT')))->toThrow(DomainException::class);
    expect(DB::table('workflow_transition_history')->where('subject_id', $id)->count())->toBe(0)
        ->and(DB::table('outbox_messages')->where('aggregate_id', $id)->count())->toBe(0);
});
