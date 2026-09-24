<?php

declare(strict_types=1);

use App\Application\Audit\AuditChainVerifier;
use App\Application\Audit\AuditWriter;
use App\Application\Engines\EngineEvaluationRecorder;
use App\Application\Engines\EngineResult;
use App\Application\Overrides\OverrideService;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** Runs a statement inside a savepoint so a DB rejection does not abort the test transaction. */
function b1bExpectDbRejection(callable $fn): void
{
    $thrown = null;
    try {
        DB::transaction($fn);
    } catch (QueryException $e) {
        $thrown = $e;
    }
    expect($thrown)->not->toBeNull();
}

function b1bResult(array $inputs = ['policy' => 'P1', 'loss_at' => '2026-09-01']): EngineResult
{
    return new EngineResult(
        engine: 'COVERAGE', outcome: 'COVERED',
        referenceAt: new DateTimeImmutable('2026-09-01T00:00:00+01:00'), recordedAsOf: new DateTimeImmutable('2026-09-24T10:00:00+01:00'),
        inputsHash: EngineResult::hashInputs($inputs),
        trace: [['rule_code' => 'POLICY_IN_FORCE', 'rule_version' => 1, 'result' => true, 'input_values' => ['b' => 2, 'a' => 1]]],
        resolvedVersions: ['product_version' => 'pv-1', 'cancellation_rule' => 'cr-3'],
    );
}

it('REQ-ENG-001 builds a deterministic envelope (key order independent inputs hash, byte-identical trace)', function () {
    expect(EngineResult::hashInputs(['a' => 1, 'b' => ['y' => 2, 'x' => 1]]))
        ->toBe(EngineResult::hashInputs(['b' => ['x' => 1, 'y' => 2], 'a' => 1]));
    expect(json_encode(b1bResult()->toArray()))->toBe(json_encode(b1bResult()->toArray()));
    expect(fn () => new EngineResult('NOPE', 'X', new DateTimeImmutable, new DateTimeImmutable, str_repeat('a', 64)))->toThrow(InvalidArgumentException::class);
    expect(fn () => new EngineResult('AML', 'X', new DateTimeImmutable, new DateTimeImmutable, 'short'))->toThrow(InvalidArgumentException::class);
});

it('REQ-ENG-001 persists evaluations append-only and mirrors them into the audit chain', function () {
    $subject = (string) Str::uuid();
    $id = app(EngineEvaluationRecorder::class)->record(b1bResult(), 'coverage.check', 'claim', $subject);

    $row = DB::table('engine_evaluations')->where('id', $id)->first();
    expect($row->outcome)->toBe('COVERED')->and(json_decode($row->resolved_versions, true))->toHaveKey('product_version');
    expect(app(EngineEvaluationRecorder::class)->forSubject('claim', $subject))->toHaveCount(1);
    expect(DB::table('audit_log')->where('action', 'engine.evaluation.recorded')->where('subject_id', $id)->exists())->toBeTrue();

    b1bExpectDbRejection(fn () => DB::table('engine_evaluations')->where('id', $id)->update(['outcome' => 'NOT_COVERED']));
    b1bExpectDbRejection(fn () => DB::table('engine_evaluations')->where('id', $id)->delete());
});

it('REQ-AUD-001 REQ-DUP-016 audit_log rejects UPDATE/DELETE/TRUNCATE and is exposed as the audit_events view', function () {
    $w = app(AuditWriter::class);
    $w->record('test.one', 'thing', (string) Str::uuid(), ['z' => 1, 'a' => ['k' => 'v']]);
    $w->record('test.two', 'thing', null);

    b1bExpectDbRejection(fn () => DB::table('audit_log')->update(['action' => 'tampered']));
    b1bExpectDbRejection(fn () => DB::table('audit_log')->delete());
    b1bExpectDbRejection(fn () => DB::statement('TRUNCATE audit_log'));

    expect(DB::table('audit_events')->whereIn('action', ['test.one', 'test.two'])->count())->toBe(2);
    expect(DB::selectOne("SELECT count(*) AS c FROM information_schema.tables WHERE table_name = 'audit_events' AND table_type = 'VIEW'")->c)->toBe(1);
});

it('REQ-AUD-001 verifies the hash chain and detects a break', function () {
    $w = app(AuditWriter::class);
    foreach (range(1, 5) as $i) {
        $w->record("test.chain.$i", 'thing', (string) Str::uuid(), ['i' => $i, 'nested' => ['b' => 1.5, 'a' => [3, 2, 1]]], 'R'.$i, ['old' => ['x' => $i - 1], 'new' => ['x' => $i]]);
    }
    expect(app(AuditChainVerifier::class)->verify())->toMatchArray(['ok' => true]);

    // Simulate tampering by a superuser who bypasses the trigger (the only way content can change).
    DB::statement('ALTER TABLE audit_log DISABLE TRIGGER audit_log_append_only');
    DB::table('audit_log')->where('action', 'test.chain.3')->update(['reason_code' => 'FORGED']);
    DB::statement('ALTER TABLE audit_log ENABLE TRIGGER audit_log_append_only');

    $result = app(AuditChainVerifier::class)->verify();
    expect($result['ok'])->toBeFalse()->and($result['first_break']['problem'])->toBe('entry_hash does not match content');
});

it('REQ-AUD-002 captures actor, old/new, reason, source, IP/device, branch and approval', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $branch = (string) Str::uuid();
    $approval = (string) Str::uuid();
    app(AuditWriter::class)->recordChange('policy.premium.changed', 'policy', (string) Str::uuid(), ['premium' => 100], ['premium' => 90], 'BROKER_DISCOUNT', [], ['branch_id' => $branch, 'approval_id' => $approval, 'device_id' => 'dev-1']);

    $row = DB::table('audit_events')->where('action', 'policy.premium.changed')->first();
    expect($row->actor_id)->toBe($user->id)->and($row->reason_code)->toBe('BROKER_DISCOUNT')
        ->and(json_decode($row->old_values, true))->toBe(['premium' => 100])->and(json_decode($row->new_values, true))->toBe(['premium' => 90])
        ->and($row->branch_id)->toBe($branch)->and($row->approval_id)->toBe($approval)->and($row->device_id)->toBe('dev-1')
        ->and($row->source)->not->toBeNull()->and($row->ip_address)->not->toBeNull()->and((int) $row->hash_version)->toBe(2);
});

it('REQ-OVR-001 enforces maker-checker and records previous/new value with reason', function () {
    [$maker, $checker] = User::factory()->count(2)->create();
    $svc = app(OverrideService::class);
    $subject = (string) Str::uuid();

    $o = $svc->request($maker->id, ['override_type' => 'PREMIUM_OVERRIDE', 'subject_type' => 'quote', 'subject_id' => $subject, 'field' => 'premium_minor',
        'previous_value' => 150000, 'new_value' => 120000, 'reason_code' => 'COMPETITIVE_MATCH', 'justification' => 'Matching a verified competitor quote.']);
    expect($o->status)->toBe('REQUESTED')->and($svc->isEffective($o))->toBeFalse();
    expect($svc->effectiveFor('quote', $subject))->toBeEmpty();

    expect(fn () => $svc->approve($o->id, $maker->id))->toThrow(ValidationException::class);
    b1bExpectDbRejection(fn () => DB::table('engine_overrides')->where('id', $o->id)->update(['status' => 'APPROVED', 'approved_by' => $maker->id, 'approved_at' => now()]));

    $approved = $svc->approve($o->id, $checker->id, 'ok');
    expect($approved->status)->toBe('APPROVED')->and($approved->approved_by)->toBe($checker->id);
    expect($svc->effectiveFor('quote', $subject, 'premium_minor'))->toHaveCount(1);
    expect(fn () => $svc->reject($o->id, $checker->id, 'late'))->toThrow(ValidationException::class);

    // Decided overrides are frozen and never deletable.
    b1bExpectDbRejection(fn () => DB::table('engine_overrides')->where('id', $o->id)->update(['decision_note' => 'edited']));
    b1bExpectDbRejection(fn () => DB::table('engine_overrides')->where('id', $o->id)->delete());

    $audit = DB::table('audit_log')->where('subject_id', $subject)->orderBy('sequence')->pluck('action')->all();
    expect($audit)->toBe(['override.requested', 'override.approved']);
    $req = DB::table('audit_log')->where('action', 'override.requested')->where('subject_id', $subject)->first();
    expect(json_decode($req->old_values, true)['value'])->toBe(150000)->and(json_decode($req->new_values, true)['value'])->toBe(120000);
});

it('REQ-OVR-001 auto-approves only within a recorded authority grant, links to evaluations, and demands a reason', function () {
    $maker = User::factory()->create();
    $svc = app(OverrideService::class);
    $evalId = app(EngineEvaluationRecorder::class)->record(b1bResult(), 'coverage.check', 'claim', (string) Str::uuid());

    $o = $svc->request($maker->id, ['override_type' => 'COVERAGE_OUTCOME', 'subject_type' => 'claim', 'engine_evaluation_id' => $evalId,
        'new_outcome' => 'REFER', 'reason_code' => 'MANUAL_REVIEW', 'justification' => 'Within my delegated claims authority.', 'authority_grant_id' => (string) Str::uuid()]);
    expect($o->status)->toBe('AUTO_APPROVED')->and($o->previous_outcome)->toBe('COVERED')->and($svc->isEffective($o))->toBeTrue();

    expect(fn () => $svc->request($maker->id, ['override_type' => 'X', 'subject_type' => 'claim', 'new_value' => 1, 'reason_code' => 'R', 'justification' => 'short']))
        ->toThrow(ValidationException::class);
    expect(fn () => $svc->request($maker->id, ['override_type' => 'X', 'subject_type' => 'claim', 'reason_code' => 'R', 'justification' => 'long enough reason']))
        ->toThrow(ValidationException::class);
});
