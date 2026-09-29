<?php

declare(strict_types=1);

use App\Application\Policies\PaymentIssuanceTrigger;
use App\Models\Proposal;
use Carbon\CarbonImmutable;

/** REQ-PRP-005: the issuance period follows the cover terms chosen on the proposal (it used to be today + 1 year). */
beforeEach(fn () => CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-29 10:30:00', 'Africa/Douala')));
afterEach(fn () => CarbonImmutable::setTestNow());

function period(?array $terms): array
{
    [$s, $e] = app(PaymentIssuanceTrigger::class)->coveragePeriod(new Proposal(['cover_terms' => $terms]));

    return [$s->toDateString(), $e->toDateTimeString()];
}

it('keeps today + 12 months when no cover terms were chosen', function () {
    expect(period(null))->toBe(['2026-09-29', '2027-09-28 23:59:59']);
});

it('starts on the chosen date and runs for the chosen months', function () {
    $terms = ['effective_rule' => 'SPECIFIED_DATE', 'start_date' => '2026-10-15', 'duration' => ['unit' => 'MONTH', 'value' => 6], 'timezone' => 'Africa/Douala'];

    expect(period($terms))->toBe(['2026-10-15', '2027-04-14 23:59:59']);
});

it('supports day durations and the immediate rule', function () {
    $terms = ['effective_rule' => 'IMMEDIATE', 'duration' => ['unit' => 'DAY', 'value' => 30], 'timezone' => 'Africa/Douala'];

    expect(period($terms))->toBe(['2026-09-29', '2026-10-28 23:59:59']);
});

it('never starts before today when a chosen date has passed by the time of payment', function () {
    $terms = ['effective_rule' => 'SPECIFIED_DATE', 'start_date' => '2026-09-20', 'duration' => ['unit' => 'MONTH', 'value' => 12], 'timezone' => 'Africa/Douala'];

    expect(period($terms))->toBe(['2026-09-29', '2027-09-28 23:59:59']);
});

it('applies the midnight rule from the next day', function () {
    $terms = ['effective_rule' => 'MIDNIGHT_RULE', 'duration' => ['unit' => 'MONTH', 'value' => 12], 'timezone' => 'Africa/Douala'];

    expect(period($terms))->toBe(['2026-09-30', '2027-09-29 23:59:59']);
});
