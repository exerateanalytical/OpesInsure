<?php

declare(strict_types=1);

/**
 * REQ-CAS-001 REQ-CAL-001 REQ-DUP-022 — ICE Engine 6 (case, task, diary, SLA, calendar).
 * Acceptance tests ICE §6.11 #1–#5 and #7 (bridge consistency is link-only; mirroring is REQ-CAS-002).
 */

use App\Application\Cases\Bridges\LegacyWorkItemBridge;
use App\Application\Cases\CaseService;
use App\Application\Cases\CaseTypeService;
use App\Application\Cases\Models\CaseType;
use App\Application\Cases\Models\QueueMember;
use App\Application\Cases\Models\SlaClock;
use App\Application\Cases\Models\WorkCase;
use App\Application\Cases\Models\WorkQueue;
use App\Application\Cases\Sla\BusinessHoursCalendar;
use App\Application\Cases\Sla\SlaService;
use App\Domain\Shared\Clock\Clock;
use App\Domain\Shared\Clock\FrozenClock;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Providers\CasesServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(CasesServiceProvider::class);
    $this->clock = new FrozenClock('2026-10-02T16:00:00+01:00'); // Friday 16:00 Douala
    $this->app->instance(Clock::class, $this->clock);
    $this->tenant = makeAuthTestTenant('cases');
});

function casesHours(string $opens = '08:00', string $closes = '17:00', ?string $branch = null): void
{
    foreach ([1, 2, 3, 4, 5] as $wd) {
        DB::table('calendar_business_hours')->insert(['id' => (string) Str::uuid(), 'jurisdiction' => 'CM', 'branch_id' => $branch, 'weekday' => $wd,
            'opens' => $opens, 'closes' => $closes, 'valid_from' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);
    }
}

function casesHoliday(string $date): void
{
    DB::table('business_calendars')->updateOrInsert(['jurisdiction' => 'CM', 'year' => (int) substr($date, 0, 4)], [
        'id' => (string) Str::uuid(), 'holidays' => json_encode([['date' => $date, 'label' => 'Test holiday']]), 'timezone' => 'Africa/Douala', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** New EFFECTIVE version of $code with the given SLA policies (maker-checker through the service). */
function casesTypeWithSla(string $code, array $sla, array $autoTasks = []): CaseType
{
    $old = CaseType::where('code', $code)->where('status', 'EFFECTIVE')->firstOrFail();
    $maker = makeAuthTestUser(test()->tenant, ['cases.admin']);
    $checker = makeAuthTestUser(test()->tenant, ['cases.admin']);
    $svc = app(CaseTypeService::class);
    $draft = $svc->draft(['code' => $code, 'states' => $old->states, 'transitions' => $old->transitions, 'sla_policies' => $sla, 'auto_tasks' => $autoTasks], $maker);

    return $svc->approve($draft, $checker);
}

function casesOpen(string $type = 'COMPLIANCE_INVESTIGATION', array $attrs = []): WorkCase
{
    return app(CaseService::class)->open(test()->tenant->id, $type, $attrs + ['title' => 'Test case'], null);
}

// ---------------------------------------------------------------- REQ-CAL-001

it('REQ-CAL-001 ICE 6.11#1: 16 business hours from Friday 16:00, Mon-Fri 08-17, Monday holiday', function () {
    casesHours();
    casesHoliday('2026-10-05'); // Monday, via the Batch 1 business_calendars table
    $cal = app(BusinessHoursCalendar::class);

    $due = $cal->addBusinessMinutes('2026-10-02T16:00:00+01:00', 16 * 60);
    // Fri 16-17 (1h) + Tue 08-17 (9h) + Wed 08-14 (6h). ICE 6.11#1 says "Wednesday 15:00", which no
    // continuous 08-17 day produces (it needs a 1h unpaid break) - reported as a spec arithmetic discrepancy.
    expect($due->format('D Y-m-d H:i'))->toBe('Wed 2026-10-07 14:00')
        ->and($cal->businessMinutesBetween('2026-10-02T16:00:00+01:00', $due))->toBe(960)
        ->and($cal->hoursSource())->toBe('JURISDICTION');
});

it('REQ-CAL-001: calendar_exceptions holidays act like business_calendars holidays (09-17 hours: Wed 16:00)', function () {
    casesHours('09:00', '17:00');
    DB::table('calendar_exceptions')->insert(['id' => (string) Str::uuid(), 'jurisdiction' => 'CM', 'date' => '2026-10-05', 'kind' => 'HOLIDAY', 'label' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
    expect(app(BusinessHoursCalendar::class)->addBusinessMinutes('2026-10-02T16:00:00+01:00', 960)->format('D H:i'))->toBe('Wed 16:00');
});

it('REQ-CAL-001: unconfigured jurisdiction falls back to whole business days; branch hours override; EXTRA_DAY opens a Saturday', function () {
    $cal = app(BusinessHoursCalendar::class);
    expect($cal->hoursSource())->toBe('UNCONFIGURED')
        ->and($cal->addBusinessMinutes('2026-10-02T16:00:00+01:00', 24 * 60)->format('D H:i'))->toBe('Mon 16:00');

    casesHours();
    $branch = (string) Str::uuid();
    DB::table('tenant_branches')->insert(['id' => $branch, 'tenant_id' => $this->tenant->id, 'code' => 'DLA', 'name' => 'Douala', 'status' => 'ACTIVE', 'timezone' => 'Africa/Douala', 'address' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('calendar_business_hours')->insert(['id' => (string) Str::uuid(), 'jurisdiction' => 'CM', 'branch_id' => $branch, 'weekday' => 5, 'opens' => '08:00', 'closes' => '12:00', 'valid_from' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('calendar_exceptions')->insert(['id' => (string) Str::uuid(), 'jurisdiction' => 'CM', 'branch_id' => $branch, 'date' => '2026-10-03', 'kind' => 'EXTRA_DAY', 'label' => 'Saturday opening', 'created_at' => now(), 'updated_at' => now()]);
    $cal->forget();

    expect($cal->hoursSource('CM', $branch))->toBe('BRANCH')
        ->and($cal->isOpenAt('2026-10-02T11:00:00+01:00', 'CM', $branch))->toBeTrue()
        ->and($cal->isOpenAt('2026-10-02T13:00:00+01:00', 'CM', $branch))->toBeFalse()
        // Branch only defines Friday 08-12; Saturday EXTRA_DAY borrows the first configured weekday's hours.
        ->and($cal->addBusinessMinutes('2026-10-02T11:00:00+01:00', 120, 'CM', $branch)->format('D H:i'))->toBe('Sat 09:00');
});

// ---------------------------------------------------------------- SLA

it('REQ-CAS-001 ICE 6.11#2: WAITING_CUSTOMER pauses the clock and resuming continues it', function () {
    casesHours();
    casesTypeWithSla('COMPLIANCE_INVESTIGATION', [['metric' => 'RESOLUTION', 'target_business_minutes' => 120, 'warn_at_pct' => 50]]);
    $this->clock->travelTo('2026-10-06T09:00:00+01:00'); // Tuesday
    $case = casesOpen();
    $svc = app(CaseService::class);
    $clock = SlaClock::where('case_id', $case->id)->where('metric', 'RESOLUTION')->firstOrFail();
    expect($clock->due_at->setTimezone('Africa/Douala')->format('H:i'))->toBe('11:00');

    $svc->transition($case, 'start', null);
    $this->clock->travelTo('2026-10-06T10:00:00+01:00');
    $svc->transition($case, 'request_info', null);
    expect($clock->refresh()->paused_since)->not->toBeNull();

    $this->clock->travelTo('2026-10-06T14:00:00+01:00');
    expect(app(SlaService::class)->tick()['breached'])->toBe(0); // paused clocks never breach

    $svc->transition($case, 'info_received', null);
    $clock->refresh();
    expect($clock->paused_since)->toBeNull()
        ->and($clock->paused_total_business_minutes)->toBe(240)
        ->and($clock->due_at->setTimezone('Africa/Douala')->format('H:i'))->toBe('15:00')
        ->and(WorkCase::find($case->id)->due_at->equalTo($clock->due_at))->toBeTrue();
    expect(DB::table('outbox_messages')->whereIn('event_name', ['sla.paused', 'sla.resumed'])->count())->toBe(2);
});

it('REQ-CAS-001 ICE 6.11#3: a breach escalates to the configured queue and emits sla.breached once', function () {
    casesHours();
    $esc = WorkQueue::create(['tenant_id' => $this->tenant->id, 'code' => 'CMP_ESCALATION', 'name' => 'Escalation', 'case_type_codes' => []]);
    casesTypeWithSla('COMPLIANCE_INVESTIGATION', [['metric' => 'FIRST_RESPONSE', 'target_business_minutes' => 60, 'warn_at_pct' => 50, 'escalate_to' => 'CMP_ESCALATION']]);
    $this->clock->travelTo('2026-10-06T09:00:00+01:00');
    $case = casesOpen();

    $this->clock->travelTo('2026-10-06T09:40:00+01:00');
    expect(app(SlaService::class)->tick()['warned'])->toBe(1);
    $this->clock->travelTo('2026-10-06T10:05:00+01:00');
    $first = app(SlaService::class)->tick();
    $second = app(SlaService::class)->tick();

    expect($first['breached'])->toBe(1)->and($first['escalated'])->toBe(1)
        ->and($second['breached'])->toBe(0)
        ->and(DB::table('outbox_messages')->where('event_name', 'sla.breached')->where('aggregate_id', $case->id)->count())->toBe(1)
        ->and(WorkCase::find($case->id)->queue_id)->toBe($esc->id)
        ->and(DB::table('case_events')->where('case_id', $case->id)->where('type', 'ESCALATED')->count())->toBe(1);
});

it('REQ-CAS-001: overdue tasks, due follow-ups and orphaned cases are flagged once by the tick', function () {
    $owner = makeAuthTestUser($this->tenant, ['cases.manage']);
    $case = casesOpen('CONFIG_GAP', ['owner_user_id' => $owner->id]);
    $svc = app(CaseService::class);
    $task = $svc->addTask($case, ['title' => 'Fix mapping', 'due_at' => '2026-10-02T17:00:00+01:00', 'assignee_user_id' => $owner->id], null);
    app(\App\Application\Cases\DiaryService::class)->add($case, ['entry_type' => 'FOLLOW_UP', 'body' => 'Call carrier', 'follow_up_at' => '2026-10-02T16:30:00+01:00'], $owner);
    $owner->update(['status' => 'SUSPENDED']);

    $this->clock->travelTo('2026-10-02T18:00:00+01:00');
    $r1 = app(SlaService::class)->tick();
    $r2 = app(SlaService::class)->tick();
    expect([$r1['tasks_overdue'], $r1['follow_ups_due'], $r1['orphans_returned']])->toBe([1, 1, 1])
        ->and([$r2['tasks_overdue'], $r2['follow_ups_due'], $r2['orphans_returned']])->toBe([0, 0, 0])
        ->and(WorkCase::find($case->id)->owner_user_id)->toBeNull()
        ->and($task->refresh()->overdue_notified_at)->not->toBeNull();
});

// ---------------------------------------------------------------- types, versions, engine

it('REQ-CAS-001: seeds the ICE 6.2 codes as sub-types with an UNVERIFIED blueprint family (OQ-6.4) and no invented SLA targets', function () {
    expect(CaseType::where('status', 'EFFECTIVE')->count())->toBe(15)
        ->and(CaseType::whereNotNull('family_code')->count())->toBe(0)
        ->and(CaseType::where('sla_policies', '!=', '[]')->count())->toBe(0)
        ->and(CaseType::where('code', 'STR')->value('default_confidentiality'))->toBe('STR_RESTRICTED')
        ->and(collect(CaseType::where('code', 'COMPLAINT')->first()->states)->pluck('code')->all())
        ->toContain('ESCALATED_NATIONAL', 'ESCALATED_CIMA');
});

it('REQ-CAS-001 ICE 6.11#4: a case opened on v1 keeps v1 transitions after v2 is approved; maker cannot approve', function () {
    $v1case = casesOpen('RECOVERY');
    $old = CaseType::where('code', 'RECOVERY')->where('status', 'EFFECTIVE')->firstOrFail();
    $maker = makeAuthTestUser($this->tenant, ['cases.admin']);
    $svc = app(CaseTypeService::class);
    $transitions = array_values(array_filter($old->transitions, fn ($t) => $t['event'] !== 'start'));
    $transitions[] = ['event' => 'triage', 'from' => ['OPEN'], 'to' => 'IN_PROGRESS'];
    $draft = $svc->draft(['code' => 'RECOVERY', 'states' => $old->states, 'transitions' => $transitions], $maker);

    expect(fn () => $svc->approve($draft, $maker))->toThrow(ApiProblemException::class);
    $v2 = $svc->approve($draft, makeAuthTestUser($this->tenant, ['cases.admin']));
    expect($v2->version)->toBe(2)->and($old->refresh()->status)->toBe('SUPERSEDED');

    $cases = app(CaseService::class);
    expect($cases->transition($v1case, 'start', null)->status)->toBe('IN_PROGRESS'); // pinned v1
    $v2case = casesOpen('RECOVERY');
    expect($v2case->case_type_id)->toBe($v2->id);
    expect(fn () => $cases->transition($v2case, 'start', null))->toThrow(ApiProblemException::class);
    expect($cases->transition($v2case, 'triage', null)->status)->toBe('IN_PROGRESS');
});

it('REQ-CAS-001: an invalid definition cannot be drafted', function () {
    $maker = makeAuthTestUser($this->tenant, ['cases.admin']);
    expect(fn () => app(CaseTypeService::class)->draft(['code' => 'BROKEN', 'states' => [['code' => 'A', 'initial' => true]], 'transitions' => [['event' => 'go', 'from' => ['A'], 'to' => 'NOPE']]], $maker))
        ->toThrow(ApiProblemException::class);
});

it('REQ-CAS-001 / REQ-WFL-001: transitions run on the StateMachineEngine; decisions gate resolution; events and decisions are append-only', function () {
    $user = makeAuthTestUser($this->tenant, ['cases.view', 'cases.manage', 'cases.decide']);
    $case = casesOpen('UW_REFERRAL');
    $svc = app(CaseService::class);
    $svc->transition($case, 'start', $user);
    $svc->transition($case, 'submit_for_decision', $user);

    try {
        $svc->transition($case, 'resolve', $user);
        $this->fail('resolve without decision must be denied');
    } catch (ApiProblemException $e) {
        expect($e->errorCode)->toBe('GUARD_FAILED')->and($e->status)->toBe(422);
    }
    $d = $svc->decide($case, ['decision_type' => 'UNDERWRITING', 'outcome' => 'APPROVED', 'rationale' => 'Within appetite'], $user);
    $svc->decide($case, ['decision_type' => 'UNDERWRITING', 'outcome' => 'REVERSED', 'rationale' => 'Wrong risk', 'reverses_decision_id' => $d->id], $user);
    expect(fn () => $svc->transition($case, 'resolve', $user))->toThrow(ApiProblemException::class); // reversed = no live decision
    $svc->decide($case, ['decision_type' => 'UNDERWRITING', 'outcome' => 'APPROVED_WITH_CONDITIONS', 'rationale' => 'Re-decided'], $user);
    $resolved = $svc->transition($case, 'resolve', $user);
    $closed = $svc->transition($resolved, 'close', $user);

    expect($closed->status)->toBe('CLOSED')->and($closed->closed_at)->not->toBeNull()
        ->and(DB::table('workflow_transition_history')->where('subject_id', $case->id)->where('machine', 'case.UW_REFERRAL')->count())->toBe(4)
        ->and(DB::table('outbox_messages')->where('aggregate_id', $case->id)->where('event_name', 'case.transitioned')->count())->toBe(4)
        ->and(DB::table('audit_log')->where('subject_id', $case->id)->where('action', 'case.transitioned')->count())->toBe(4)
        ->and(DB::table('case_events')->where('case_id', $case->id)->pluck('seq')->all())->toBe(range(1, DB::table('case_events')->where('case_id', $case->id)->count()));

    expect(fn () => DB::table('case_events')->where('case_id', $case->id)->update(['type' => 'X']))->toThrow(\Illuminate\Database\QueryException::class);
    expect(fn () => DB::table('case_decisions')->where('id', $d->id)->delete())->toThrow(\Illuminate\Database\QueryException::class);
});

it('REQ-CAS-001: cancel and reopen require a reason; confidentiality cannot go below the type default', function () {
    $case = casesOpen();
    expect(fn () => app(CaseService::class)->transition($case, 'cancel', null))->toThrow(ApiProblemException::class)
        ->and(fn () => casesOpen('STR', ['confidentiality' => 'NORMAL']))->toThrow(ApiProblemException::class)
        ->and(app(CaseService::class)->transition($case, 'cancel', null, 'Opened in error')->status)->toBe('CANCELLED');
});

// ---------------------------------------------------------------- API, visibility, routing

it('REQ-CAS-001 ICE 6.11#5: STR_RESTRICTED cases are invisible through the API without cases.str.view', function () {
    $str = casesOpen('STR', ['title' => 'Suspicious transfer']);
    $normal = casesOpen('CONFIG_GAP');
    $plain = makeAuthTestUser($this->tenant, ['cases.view']);
    $mlro = makeAuthTestUser($this->tenant, ['cases.view', 'cases.str.view']);
    $h = tenantHeader($this->tenant);

    Passport::actingAs($plain);
    $ids = collect($this->getJson('/api/v1/cases', $h)->assertOk()->json('data'))->pluck('id');
    expect($ids)->toContain($normal->id)->not->toContain($str->id);
    $this->getJson("/api/v1/cases/{$str->id}", $h)->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
    expect(WorkCase::query()->whereKey($str->id)->exists())->toBeFalse(); // query layer, not only the API

    Passport::actingAs($mlro);
    $this->getJson("/api/v1/cases/{$str->id}", $h)->assertOk()->assertJsonPath('data.confidentiality', 'STR_RESTRICTED');
});

it('REQ-CAS-001: API open/transition/assign with error envelope, idempotency and permissions', function () {
    $user = makeAuthTestUser($this->tenant, ['cases.view', 'cases.manage', 'cases.assign']);
    $other = makeAuthTestUser($this->tenant, ['cases.view']);
    $outsider = makeAuthTestUser(makeAuthTestTenant('other'), ['cases.view']);
    $h = tenantHeader($this->tenant) + ['X-Correlation-ID' => 'corr-cases-0001'];
    Passport::actingAs($user);

    $r = $this->postJson('/api/v1/cases', ['case_type' => 'CONFIG_GAP', 'title' => 'Missing tariff', 'idempotency_key' => 'k-1'], $h)->assertCreated();
    $id = $r->json('data.id');
    expect($r->json('data.available_events'))->toContain('start', 'cancel');
    $this->postJson('/api/v1/cases', ['case_type' => 'CONFIG_GAP', 'title' => 'Missing tariff', 'idempotency_key' => 'k-1'], $h)->assertOk()->assertJsonPath('data.id', $id);
    expect(DB::table('case_events')->where('case_id', $id)->value('correlation_id'))->toBe('corr-cases-0001');

    $this->postJson("/api/v1/cases/{$id}/transitions", ['event' => 'close'], $h)->assertStatus(409)
        ->assertJsonPath('code', 'INVALID_TRANSITION')->assertJsonPath('correlation_id', 'corr-cases-0001');
    $this->postJson("/api/v1/cases/{$id}/transitions", ['event' => 'start'], $h)->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');

    $this->postJson("/api/v1/cases/{$id}/assign", ['owner_user_id' => $outsider->id], $h)->assertStatus(422)->assertJsonPath('code', 'ASSIGNEE_NOT_IN_TENANT');
    $this->postJson("/api/v1/cases/{$id}/assign", ['owner_user_id' => $other->id, 'reason' => 'workload'], $h)->assertOk()->assertJsonPath('data.owner_user_id', $other->id);
    expect(DB::table('case_events')->where('case_id', $id)->where('type', 'ASSIGNED')->count())->toBe(1);

    $this->postJson("/api/v1/cases/{$id}/decisions", ['decision_type' => 'X', 'outcome' => 'Y', 'rationale' => 'Z'], $h)->assertForbidden();
    $this->postJson("/api/v1/cases/{$id}/tasks", ['title' => 'Load tariff', 'assignee_user_id' => $user->id, 'due_in_business_minutes' => 60], $h)->assertCreated();
    $this->postJson("/api/v1/cases/{$id}/diary", ['entry_type' => 'NOTE', 'body' => 'Asked product team'], $h)->assertCreated();
    $this->getJson("/api/v1/cases/{$id}", $h)->assertOk()->assertJsonPath('data.open_tasks', 1)->assertJsonPath('data.case_type.code', 'CONFIG_GAP');

    Passport::actingAs($outsider);
    $this->getJson("/api/v1/cases/{$id}", tenantHeader($this->tenant))->assertForbidden();
});

it('REQ-CAS-001: queue routing (least-loaded) and pull-next; tasks run on the task machine', function () {
    $a = makeAuthTestUser($this->tenant, ['cases.view', 'cases.manage']);
    $b = makeAuthTestUser($this->tenant, ['cases.view', 'cases.manage']);
    $auto = WorkQueue::create(['tenant_id' => $this->tenant->id, 'code' => 'RECOVERIES', 'name' => 'Recoveries', 'case_type_codes' => ['RECOVERY'], 'routing_rule' => 'LEAST_LOADED']);
    $pull = WorkQueue::create(['tenant_id' => $this->tenant->id, 'code' => 'GAPS', 'name' => 'Gaps', 'case_type_codes' => ['CONFIG_GAP'], 'routing_rule' => 'PULL']);
    foreach ([$a, $b] as $u) {
        QueueMember::create(['queue_id' => $auto->id, 'user_id' => $u->id, 'capacity' => 5]);
    }
    QueueMember::create(['queue_id' => $pull->id, 'user_id' => $a->id]);

    $c1 = casesOpen('RECOVERY');
    $c2 = casesOpen('RECOVERY');
    expect([$c1->queue_id, $c2->queue_id])->toBe([$auto->id, $auto->id])
        ->and(collect([$c1->owner_user_id, $c2->owner_user_id])->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());

    $gap = casesOpen('CONFIG_GAP', ['priority' => 'HIGH']);
    expect($gap->owner_user_id)->toBeNull();
    Passport::actingAs($b);
    $this->postJson("/api/v1/queues/{$pull->id}/next", [], tenantHeader($this->tenant))->assertStatus(403)->assertJsonPath('code', 'NOT_QUEUE_MEMBER');
    Passport::actingAs($a);
    $this->postJson("/api/v1/queues/{$pull->id}/next", [], tenantHeader($this->tenant))->assertOk()->assertJsonPath('data.id', $gap->id)->assertJsonPath('data.owner_user_id', $a->id);
    $this->postJson("/api/v1/queues/{$pull->id}/next", [], tenantHeader($this->tenant))->assertOk()->assertJsonPath('data', null);

    $task = app(CaseService::class)->addTask($gap, ['title' => 'Check', 'assignee_user_id' => $a->id], $a);
    $this->postJson("/api/v1/cases/{$gap->id}/tasks/{$task->id}/transitions", ['status' => 'DONE', 'result' => ['ok' => true]], tenantHeader($this->tenant))->assertOk()->assertJsonPath('data.status', 'DONE');
    $this->postJson("/api/v1/cases/{$gap->id}/tasks/{$task->id}/transitions", ['status' => 'IN_PROGRESS'], tenantHeader($this->tenant))->assertStatus(409);
    expect(DB::table('workflow_transition_history')->where('subject_id', $task->id)->where('machine', 'case_task')->count())->toBe(1);
});

it('REQ-CAS-001: auto-tasks from the type version are created on open with business-minute due dates', function () {
    casesHours();
    casesTypeWithSla('DOC_INTAKE_EXCEPTION', [], [['on' => 'event:opened', 'title' => 'Classify document', 'task_template_code' => 'CLASSIFY', 'due_offset_business_minutes' => 120]]);
    $case = casesOpen('DOC_INTAKE_EXCEPTION');
    $task = $case->tasks()->firstOrFail();
    // Friday 16:00 + 2 business hours = Monday 09:00 (08-17 hours, no holiday).
    expect($task->template_code)->toBe('CLASSIFY')->and($task->due_at->setTimezone('Africa/Douala')->format('D H:i'))->toBe('Mon 09:00');
});

// ---------------------------------------------------------------- REQ-DUP-022 projections + bridge

it('REQ-DUP-022: My Work unifies case tasks, owned cases, follow-ups and legacy work items; linking a legacy ticket is idempotent and de-duplicates it', function () {
    $me = makeAuthTestUser($this->tenant, ['cases.view', 'cases.manage', 'cases.admin']);
    $case = casesOpen('CONFIG_GAP', ['owner_user_id' => $me->id]);
    app(CaseService::class)->addTask($case, ['title' => 'Mine', 'assignee_user_id' => $me->id], null);
    $ticket = (string) Str::uuid();
    DB::table('support_tickets')->insert(['id' => $ticket, 'tenant_id' => $this->tenant->id, 'ticket_number' => 'TCK-1', 'type' => 'COMPLAINT', 'category' => 'SERVICE',
        'priority' => 'NORMAL', 'status' => 'OPEN', 'subject' => 'Late refund', 'description' => 'x', 'assigned_to' => $me->id, 'sla_due_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

    Passport::actingAs($me);
    $h = tenantHeader($this->tenant);
    $work = collect($this->getJson('/api/v1/me/work', $h)->assertOk()->json('data'));
    expect($work->pluck('kind')->sort()->values()->all())->toBe(['CASE', 'LEGACY_CASE', 'TASK'])
        ->and($work->firstWhere('source', 'support_tickets')['id'])->toBe($ticket);

    $first = $this->postJson('/api/v1/admin/cases/links', ['source' => 'support_tickets', 'id' => $ticket], $h)->assertCreated();
    $this->postJson('/api/v1/admin/cases/links', ['source' => 'support_tickets', 'id' => $ticket], $h)->assertOk()->assertJsonPath('data.case_id', $first->json('data.case_id'));
    expect(DB::table('support_tickets')->where('id', $ticket)->value('case_id'))->toBe($first->json('data.case_id'))
        ->and(DB::table('support_tickets')->where('id', $ticket)->value('status'))->toBe('OPEN') // legacy keeps its own state
        ->and(WorkCase::find($first->json('data.case_id'))->case_type_code)->toBe('COMPLAINT');

    $work = collect($this->getJson('/api/v1/me/work', $h)->json('data'));
    expect($work->where('source', 'support_tickets')->count())->toBe(0)
        ->and($work->where('kind', 'CASE')->count())->toBe(2);

    DB::table('support_tickets')->where('id', $ticket)->update(['type' => 'SUPPORT', 'case_id' => null]);
    $this->postJson('/api/v1/admin/cases/links', ['source' => 'support_tickets', 'id' => $ticket], $h)->assertStatus(422)->assertJsonPath('code', 'SOURCE_NOT_BRIDGEABLE');
    expect(app(LegacyWorkItemBridge::class))->toBeInstanceOf(LegacyWorkItemBridge::class);
});

it('REQ-CAL-001: calendar admin API adds hours/exceptions and answers business-time questions', function () {
    $admin = makeAuthTestUser($this->tenant, ['cases.calendar.manage']);
    Passport::actingAs($admin);
    $h = tenantHeader($this->tenant);
    foreach ([1, 2, 3, 4, 5] as $wd) {
        $this->postJson('/api/v1/admin/calendars/hours', ['jurisdiction' => 'CM', 'weekday' => $wd, 'opens' => '08:00', 'closes' => '17:00', 'valid_from' => '2026-01-01'], $h)->assertCreated();
    }
    $this->postJson('/api/v1/admin/calendars/hours', ['jurisdiction' => 'CM', 'weekday' => 1, 'opens' => '17:00', 'closes' => '08:00', 'valid_from' => '2026-01-01'], $h)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    $this->postJson('/api/v1/admin/calendars/exceptions', ['jurisdiction' => 'CM', 'date' => '2026-10-05', 'kind' => 'HOLIDAY', 'label' => 'Test'], $h)->assertCreated();
    $this->getJson('/api/v1/admin/calendars/business-time?from=2026-10-02T16:00:00%2B01:00&minutes=960', $h)->assertOk()
        ->assertJsonPath('data.hours_source', 'JURISDICTION')->assertJsonPath('data.due_at', '2026-10-07T14:00:00+01:00');
    expect(DB::table('audit_log')->where('action', 'calendar.business_hours.added')->count())->toBe(5);
});

it('REQ-CAS-001: Filament "Cases & tasks" screens render and respect case confidentiality', function () {
    $str = casesOpen('STR', ['title' => 'Hidden STR']);
    $gap = casesOpen('CONFIG_GAP', ['title' => 'Visible gap']);
    // Platform admin: configuration screens only; case records are business data (REQ-RBAC-004).
    $this->actingAs(makeAuthTestSystemAdmin($this->tenant), 'web');
    foreach (['/admin/case-types', '/admin/work-queues', '/admin/work-queues/create', '/admin/business-hours', '/admin/calendar-exceptions'] as $url) {
        $this->get($url)->assertOk();
    }
    $this->get('/admin/case-records')->assertForbidden();

    $this->flushSession();
    app('auth')->forgetGuards();
    $this->actingAs(makeAuthTestUser($this->tenant, ['cases.view', 'cases.restricted.view', 'cases.str.view'], 'COMPLIANCE_ADMIN'), 'web');
    foreach (['/admin/case-records', "/admin/case-records/{$gap->id}", '/admin/case-tasks'] as $url) {
        $this->get($url)->assertOk();
    }
    $this->get('/admin/case-records')->assertSee($str->case_number)->assertSee('Cases &amp; tasks', false);

    $this->flushSession();
    app('auth')->forgetGuards();
    $this->actingAs(makeAuthTestUser($this->tenant, ['cases.view'], 'BROKER_STAFF'), 'web');
    $this->get('/admin/case-records')->assertOk()->assertSee($gap->case_number)->assertDontSee($str->case_number);
    $this->get('/admin/case-types')->assertForbidden();
});
