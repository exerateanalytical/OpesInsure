<?php

declare(strict_types=1);

/**
 * REQ-RUL-002 REQ-RUL-003 REQ-RUL-004 — structured expression engine (PRE §19–22): §20 operators, three-valued
 * logic, priority + stop_processing, deterministic trace, no code; eligibility and completeness combination.
 */

use App\Domain\Rules\EligibilityOutcome;
use App\Domain\Rules\Expression\ExpressionEvaluator;
use App\Domain\Rules\Expression\ExpressionValidator;
use App\Domain\Rules\RuleDefinition;
use App\Domain\Rules\RuleSetDefinition;
use App\Domain\Rules\RuleSetEvaluator;

function b5bRx(array|bool $e, array $facts): ?bool
{
    return (new ExpressionEvaluator)->evaluate($e, $facts)['result'];
}

function b5bCmp(string $op, string $fact, mixed $value = null): array
{
    return ['op' => $op, 'left' => ['fact' => $fact], 'right' => ['value' => $value]];
}

it('REQ-RUL-002 implements every §20 comparison operator', function () {
    $f = ['vehicle' => ['age' => 12, 'usage' => 'TAXI', 'value' => '6500000'], 'tags' => ['FLEET', 'GPS'], 'name' => 'Douala Port', 'blank' => null];
    expect(b5bRx(b5bCmp('EQUAL', 'vehicle.usage', 'TAXI'), $f))->toBeTrue()
        ->and(b5bRx(b5bCmp('NOT_EQUAL', 'vehicle.usage', 'TAXI'), $f))->toBeFalse()
        ->and(b5bRx(b5bCmp('GT', 'vehicle.age', 10), $f))->toBeTrue()
        ->and(b5bRx(b5bCmp('GTE', 'vehicle.age', 12), $f))->toBeTrue()
        ->and(b5bRx(b5bCmp('LT', 'vehicle.age', 12), $f))->toBeFalse()
        ->and(b5bRx(b5bCmp('LTE', 'vehicle.value', 6500000), $f))->toBeTrue()
        ->and(b5bRx(b5bCmp('IN', 'vehicle.usage', ['TAXI', 'BUS']), $f))->toBeTrue()
        ->and(b5bRx(b5bCmp('NOT_IN', 'vehicle.usage', ['TAXI']), $f))->toBeFalse()
        ->and(b5bRx(b5bCmp('BETWEEN', 'vehicle.age', [5, 12]), $f))->toBeTrue()
        ->and(b5bRx(b5bCmp('BETWEEN', 'vehicle.age', [5, 12]) + ['inclusive' => false], $f))->toBeFalse()
        ->and(b5bRx(b5bCmp('CONTAINS', 'tags', 'GPS'), $f))->toBeTrue()
        ->and(b5bRx(b5bCmp('CONTAINS', 'name', 'Port'), $f))->toBeTrue()
        ->and(b5bRx(['op' => 'EXISTS', 'left' => ['fact' => 'vehicle.age']], $f))->toBeTrue()
        ->and(b5bRx(['op' => 'NOT_EXISTS', 'left' => ['fact' => 'blank']], $f))->toBeTrue()
        ->and(b5bRx(['op' => 'EQUALS', 'left' => ['fact' => 'vehicle.usage'], 'right' => ['value' => 'TAXI']], $f))->toBeTrue(); // legacy alias
});

it('REQ-RUL-002 uses three-valued logic: a missing fact is UNKNOWN, not false', function () {
    $f = ['a' => 1];
    expect(b5bRx(b5bCmp('GT', 'missing', 3), $f))->toBeNull()
        ->and(b5bRx(['op' => 'AND', 'args' => [b5bCmp('EQUAL', 'a', 2), b5bCmp('GT', 'missing', 3)]], $f))->toBeFalse()
        ->and(b5bRx(['op' => 'AND', 'args' => [b5bCmp('EQUAL', 'a', 1), b5bCmp('GT', 'missing', 3)]], $f))->toBeNull()
        ->and(b5bRx(['op' => 'OR', 'args' => [b5bCmp('EQUAL', 'a', 1), b5bCmp('GT', 'missing', 3)]], $f))->toBeTrue()
        ->and(b5bRx(['op' => 'NOT', 'arg' => b5bCmp('GT', 'missing', 3)], $f))->toBeNull()
        ->and((new ExpressionEvaluator)->evaluate(b5bCmp('GT', 'missing', 3), $f)['missing'])->toBe(['missing']);
});

it('REQ-RUL-002 evaluates whitelisted functions against the caller-supplied reference date', function () {
    $f = ['vehicle' => ['first_registration_date' => '1998-03-01'], 'context.today' => '2026-09-24'];
    $age = ['fn' => 'YEARS_BETWEEN', 'args' => [['fact' => 'vehicle.first_registration_date'], ['fact' => 'context.today']]];
    expect(b5bRx(['op' => 'GT', 'left' => $age, 'right' => ['value' => 25]], $f))->toBeTrue()
        ->and(b5bRx(['op' => 'EQUAL', 'left' => ['fn' => 'ADD', 'args' => [['value' => 2], ['value' => 3]]], 'right' => ['value' => 5]], $f))->toBeTrue();
});

it('REQ-RUL-002 rejects anything outside the structured grammar (no uploaded code)', function () {
    $v = new ExpressionValidator;
    expect($v->validate(b5bCmp('GT', 'vehicle.age', 25)))->toBe([])
        ->and($v->validate(true))->toBe([])
        ->and($v->validate(['op' => 'EVAL', 'left' => ['value' => 'system("rm -rf /")']]))->not->toBe([])
        ->and($v->validate(['op' => 'GT', 'left' => ['fn' => 'exec', 'args' => []], 'right' => ['value' => 1]]))->not->toBe([])
        ->and($v->validate(['op' => 'GT', 'left' => ['fact' => 'a; DROP TABLE'], 'right' => ['value' => 1]]))->not->toBe([])
        ->and($v->validate(['op' => 'IN', 'left' => ['fact' => 'a'], 'right' => ['value' => 'x']]))->not->toBe([])
        ->and($v->validate(['op' => 'AND', 'args' => []]))->not->toBe([])
        ->and($v->validate('1 == 1'))->not->toBe([]);
});

function b5bRuleSet(array $rules, string $domain = 'ELIGIBILITY'): RuleSetDefinition
{
    return new RuleSetDefinition('TEST_SET', $domain, 3, $rules, 'set-id', str_repeat('a', 64));
}

it('REQ-RUL-002 REQ-RUL-003 orders by priority then code, honours stop_processing and traces every evaluated rule', function () {
    $set = b5bRuleSet([
        new RuleDefinition('Z_REFER_OLD', 50, false, b5bCmp('GT', 'age', 15), ['result' => 'REFER_TO_UNDERWRITING', 'reason_code' => 'OLD_VEHICLE']),
        new RuleDefinition('A_TOO_OLD', 10, true, b5bCmp('GT', 'age', 25), ['result' => 'INELIGIBLE', 'reason_code' => 'VEHICLE_TOO_OLD']),
        new RuleDefinition('M_TAXI', 50, false, b5bCmp('EQUAL', 'usage', 'TAXI'), ['result' => 'CONDITIONAL', 'reason_code' => 'TAXI_CONDITIONS', 'conditions' => ['GPS_REQUIRED']]),
    ]);
    $ev = new RuleSetEvaluator;

    $old = $ev->evaluate($set, ['age' => 30, 'usage' => 'TAXI']);
    expect(array_column($old['trace'], 'rule_code'))->toBe(['A_TOO_OLD'])->and($old['stopped_at'])->toBe('A_TOO_OLD');
    expect(RuleSetEvaluator::combineEligibility($old)['outcome'])->toBe(EligibilityOutcome::INELIGIBLE);

    $mid = $ev->evaluate($set, ['age' => 20, 'usage' => 'TAXI']);
    expect(array_column($mid['trace'], 'rule_code'))->toBe(['A_TOO_OLD', 'M_TAXI', 'Z_REFER_OLD'])
        ->and(array_column($mid['trace'], 'result'))->toBe(['FALSE', 'TRUE', 'TRUE']);
    $c = RuleSetEvaluator::combineEligibility($mid);
    expect($c['outcome'])->toBe(EligibilityOutcome::REFER_TO_UNDERWRITING)->and($c['reasons'])->toBe(['TAXI_CONDITIONS', 'OLD_VEHICLE']);

    $taxi = RuleSetEvaluator::combineEligibility($ev->evaluate($set, ['age' => 3, 'usage' => 'TAXI']));
    expect($taxi['outcome'])->toBe(EligibilityOutcome::CONDITIONAL)->and($taxi['conditions'])->toBe(['GPS_REQUIRED'])->and($taxi['outcome']->quotable())->toBeTrue();

    expect(RuleSetEvaluator::combineEligibility($ev->evaluate($set, ['age' => 3, 'usage' => 'PRIVATE']))['outcome'])->toBe(EligibilityOutcome::ELIGIBLE);

    $unknown = RuleSetEvaluator::combineEligibility($ev->evaluate($set, ['usage' => 'PRIVATE']));
    expect($unknown['outcome'])->toBe(EligibilityOutcome::MORE_INFORMATION_REQUIRED)->and($unknown['missing'])->toBe(['age']);

    // Determinism: the same inputs give a byte-identical trace.
    expect(json_encode($ev->evaluate($set, ['age' => 20, 'usage' => 'TAXI'])['trace']))->toBe(json_encode($mid['trace']));
});

it('REQ-RUL-003 maps SCF / legacy outcome names onto the PRE §19 set', function () {
    expect(EligibilityOutcome::parse('UNDERWRITING_REFERRAL'))->toBe(EligibilityOutcome::REFER_TO_UNDERWRITING)
        ->and(EligibilityOutcome::parse('REQUIRES_DOCUMENT'))->toBe(EligibilityOutcome::MORE_INFORMATION_REQUIRED)
        ->and(EligibilityOutcome::worst(EligibilityOutcome::CONDITIONAL, EligibilityOutcome::INELIGIBLE, EligibilityOutcome::REFER_TO_UNDERWRITING))->toBe(EligibilityOutcome::INELIGIBLE);
});

it('REQ-RUL-004 combines completeness rules into COMPLETE / COMPLETE_WITH_WARNINGS / INCOMPLETE', function () {
    $set = b5bRuleSet([
        new RuleDefinition('VIN_MISSING', 10, false, ['op' => 'NOT_EXISTS', 'left' => ['fact' => 'vehicle.vin']], ['result' => 'BLOCK', 'reason_code' => 'VIN_REQUIRED']),
        new RuleDefinition('PHOTO_MISSING', 20, false, ['op' => 'NOT_EXISTS', 'left' => ['fact' => 'vehicle.photo']], ['result' => 'WARN', 'reason_code' => 'PHOTO_RECOMMENDED']),
    ], 'COMPLETENESS');
    $ev = new RuleSetEvaluator;
    expect(RuleSetEvaluator::combineCompleteness($ev->evaluate($set, []))['outcome'])->toBe('INCOMPLETE')
        ->and(RuleSetEvaluator::combineCompleteness($ev->evaluate($set, ['vehicle' => ['vin' => 'X']])))->toMatchArray(['outcome' => 'COMPLETE_WITH_WARNINGS', 'blocking' => false, 'warnings' => ['PHOTO_RECOMMENDED']])
        ->and(RuleSetEvaluator::combineCompleteness($ev->evaluate($set, ['vehicle' => ['vin' => 'X', 'photo' => 'doc-1']]))['outcome'])->toBe('COMPLETE');
});
