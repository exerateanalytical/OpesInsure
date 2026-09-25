<?php

declare(strict_types=1);

use App\Application\Events\Catalogue\DomainEventCatalogue;
use App\Application\Payments\WebhookProcessingService;
use App\Application\Policies\Lapse\PolicyRecoveryService;
use App\Application\Policies\Lapse\PolicySuspender;
use App\Application\Policies\Lapse\PremiumDefaultSweep;
use App\Application\Policies\Lapse\SuspensionServicePolicySuspender;
use App\Application\Policies\PolicyIssuanceService;
use App\Models\Policy;
use App\Models\PolicyIssuanceRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function b8pcPolicy(): Policy
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => [
        'offer_id' => null, 'quote_id' => $f['quote']->id, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF',
        'coverage_snapshot' => ['coverages' => [['code' => 'RC', 'name' => ['en' => 'Liability'], 'mandatory' => true, 'optional' => false, 'limit_minor' => 50_000_000, 'deductible_minor' => null]]],
    ]]);
    $payment = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'PENDING_CUSTOMER', 'requested_by' => $f['user']->id]);
    app(WebhookProcessingService::class)->process('fake', 'evt-'.Str::uuid(), [
        'payment_reference' => $payment->provider_reference, 'amount_minor' => $payment->amount_minor, 'currency' => $payment->currency, 'status' => 'SUCCEEDED',
    ], 'sig');
    $request = PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->firstOrFail();

    return app(PolicyIssuanceService::class)->approve($request, ['carrier_reference' => 'CR-8'], b8pcUser());
}

function b8pcUser(): User
{
    return User::create(['full_name' => 'Ops '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

function b8pcRule(array $r): string
{
    $id = (string) Str::uuid();
    DB::table('premium_cover_rules')->insert($r + [
        'id' => $id, 'name' => $r['code'], 'premium_statuses' => json_encode(['OVERDUE', 'PARTIALLY_PAID']), 'is_exception' => false,
        'activation_rule' => 'true', 'priority' => 100, 'effective_from' => '2020-01-01', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function b8pcInstalment(Policy $p, string $due, int $seq = 1, int $amount = 50000): string
{
    $id = (string) Str::uuid();
    DB::table('policy_premium_instalments')->insert(['id' => $id, 'tenant_id' => $p->tenant_id, 'policy_id' => $p->id, 'sequence' => $seq,
        'due_date' => $due, 'amount_minor' => $amount, 'currency' => 'XAF', 'status' => 'DUE', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function b8pcEvents(string $name): int
{
    return DB::table('outbox_messages')->where('event_name', $name)->count();
}

beforeEach(function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
});

it('REQ-POL-008/010 applies GRACE then SUSPEND_ON_DEFAULT then LAPSED from the rule engine, idempotently', function () {
    $policy = b8pcPolicy();
    expect($policy->status)->toBe('ACTIVE');
    b8pcRule(['code' => 'GRACE_10', 'outcome' => 'GRACE', 'grace_days' => 10, 'priority' => 10,
        'activation_rule' => json_encode(['op' => 'LTE', 'left' => ['fact' => 'premium.days_overdue'], 'right' => ['value' => 10]])]);
    b8pcRule(['code' => 'SUSPEND_ON_DEFAULT', 'outcome' => 'COVER_SUSPENDED', 'lapse_after_days' => 30, 'priority' => 20]);
    $today = CarbonImmutable::parse('2026-11-20');
    $inst = b8pcInstalment($policy, '2026-11-15');
    $sweep = app(PremiumDefaultSweep::class);

    expect($sweep->run($today))->toMatchArray(['evaluated' => 1, 'grace' => 1, 'defaulted' => 0]);
    $row = DB::table('policy_premium_instalments')->find($inst);
    expect($row->status)->toBe('GRACE')->and($row->grace_ends_on)->toBe('2026-11-25')->and($row->last_outcome)->toBe('GRACE');
    $sweep->run($today->addDay());
    expect(b8pcEvents('policy.premium.grace_started'))->toBe(1)->and($policy->refresh()->status)->toBe('ACTIVE');

    // Day 12: grace rule no longer activates, SUSPEND_ON_DEFAULT does.
    expect($sweep->run($today->addDays(7)))->toMatchArray(['defaulted' => 1, 'suspended' => 1]);
    expect(DB::table('policy_premium_instalments')->find($inst)->status)->toBe('DEFAULTED')
        ->and($policy->refresh()->status)->toBe('SUSPENDED')
        ->and(DB::table('policy_status_history')->where('policy_id', $policy->id)->where('to_status', 'SUSPENDED')->value('reason_code'))->toBe('PREMIUM_DEFAULT')
        ->and(b8pcEvents('policy.premium.defaulted'))->toBe(1)->and(b8pcEvents('policy.premium.suspended'))->toBe(1);

    $this->travelTo($today->addDays(7));
    DB::table('policy_premium_instalments')->where('id', $inst)->update(['defaulted_at' => $today->addDays(7)]);
    expect($sweep->run($today->addDays(20))['lapsed'])->toBe(0);
    expect($sweep->run($today->addDays(37))['lapsed'])->toBe(1);
    expect(DB::table('policy_premium_instalments')->find($inst)->status)->toBe('LAPSED')->and(b8pcEvents('policy.premium.lapsed'))->toBe(1);
    expect($sweep->run($today->addDays(40))['evaluated'])->toBe(0);
});

it('REQ-POL-008 NO_COVER suspends at once; no rule records UNDETERMINED and assumes nothing', function () {
    $policy = b8pcPolicy();
    $inst = b8pcInstalment($policy, '2026-11-01');
    $sweep = app(PremiumDefaultSweep::class);

    $sweep->run(CarbonImmutable::parse('2026-11-05'));
    expect(DB::table('policy_premium_instalments')->find($inst))->status->toBe('OVERDUE')->last_outcome->toBe('UNDETERMINED')
        ->and($policy->refresh()->status)->toBe('ACTIVE');

    b8pcRule(['code' => 'NO_COVER_UNTIL_PAID', 'outcome' => 'NO_COVER']);
    $sweep->run(CarbonImmutable::parse('2026-11-06'));
    expect(DB::table('policy_premium_instalments')->find($inst)->status)->toBe('DEFAULTED')->and($policy->refresh()->status)->toBe('SUSPENDED');
    // null lapse_after_days: never lapses automatically.
    expect($sweep->run(CarbonImmutable::parse('2027-06-01'))['lapsed'])->toBe(0);
});

it('REQ-POL-010 recovery: arrears must clear, maker-checker, policy back to ACTIVE', function () {
    $policy = b8pcPolicy();
    b8pcRule(['code' => 'SUSPEND', 'outcome' => 'COVER_SUSPENDED']);
    $inst = b8pcInstalment($policy, '2026-11-01', 1, 40000);
    b8pcInstalment($policy, '2027-12-01', 2, 40000); // not yet due: not arrears
    app(PremiumDefaultSweep::class)->run(CarbonImmutable::parse('2026-11-10'));
    expect($policy->refresh()->status)->toBe('SUSPENDED');

    $svc = app(PolicyRecoveryService::class);
    [$maker, $checker] = [b8pcUser(), b8pcUser()];
    $case = $svc->open($policy, ['reason_code' => 'CUSTOMER_REQUEST'], $maker);
    expect((int) $case->arrears_minor)->toBe(40000)->and($case->policy_status_at_open)->toBe('SUSPENDED');
    expect(fn () => $svc->open($policy, ['reason_code' => 'X'], $maker))->toThrow(ValidationException::class);
    expect(fn () => $svc->approve($case->id, $checker))->toThrow(ValidationException::class, 'ARREARS_OUTSTANDING');

    $svc->settleInstalment($inst, 15000, null);
    expect(DB::table('policy_premium_instalments')->find($inst)->status)->toBe('DEFAULTED');
    $svc->settleInstalment($inst, 25000, (string) Str::uuid());
    expect(DB::table('policy_premium_instalments')->find($inst)->status)->toBe('PAID')->and($svc->arrears($policy->id))->toBe(0);

    expect(fn () => $svc->approve($case->id, $maker))->toThrow(ValidationException::class, 'MAKER_CHECKER');
    $recovered = $svc->approve($case->id, $checker);
    expect($recovered->status)->toBe('ACTIVE')
        ->and(DB::table('policy_recovery_cases')->find($case->id)->status)->toBe('APPROVED')
        ->and(DB::table('policy_status_history')->where('policy_id', $policy->id)->where('reason_code', 'POLICY_RECOVERED')->count())->toBe(1)
        ->and(b8pcEvents('policy.recovery.requested'))->toBe(1)->and(b8pcEvents('policy.recovery.approved'))->toBe(1);
});

it('REQ-POL-010 recovers a LAPSED (expired) policy only with a future coverage end; rejection leaves it lapsed', function () {
    $policy = b8pcPolicy();
    $policy->update(['status' => 'LAPSED', 'coverage_ends_at' => now()->subDays(20)]);
    $svc = app(PolicyRecoveryService::class);
    [$maker, $checker] = [b8pcUser(), b8pcUser()];

    $case = $svc->open($policy, ['reason_code' => 'LATE_RENEWAL'], $maker);
    expect(fn () => $svc->approve($case->id, $checker))->toThrow(ValidationException::class, 'COVERAGE_END_REQUIRED');
    expect(fn () => $svc->approve($case->id, $checker, now()->subDay()->toIso8601String()))->toThrow(ValidationException::class, 'COVERAGE_END_INVALID');
    $svc->reject($case->id, $checker, 'Customer withdrew');
    expect($policy->refresh()->status)->toBe('LAPSED')->and(b8pcEvents('policy.recovery.rejected'))->toBe(1);

    $case2 = $svc->open($policy, ['reason_code' => 'LATE_RENEWAL', 'new_coverage_ends_at' => now()->addYear()->toIso8601String()], $maker);
    $p = $svc->approve($case2->id, $checker);
    expect($p->status)->toBe('ACTIVE')->and($p->coverage_ends_at->isFuture())->toBeTrue();

    $active = b8pcPolicy();
    expect(fn () => $svc->open($active, ['reason_code' => 'X'], $maker))->toThrow(ValidationException::class, 'POLICY_NOT_RECOVERABLE');
});

it('wires the suspender seam, the catalogue events and the scheduled command', function () {
    expect(app(PolicySuspender::class))->toBeInstanceOf(SuspensionServicePolicySuspender::class);
    foreach (['policy.premium.grace_started', 'policy.premium.defaulted', 'policy.premium.suspended', 'policy.premium.lapsed',
        'policy.premium.instalment_settled', 'policy.recovery.requested', 'policy.recovery.approved', 'policy.recovery.rejected'] as $e) {
        expect(DomainEventCatalogue::has($e))->toBeTrue();
    }
    $commands = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode("\n");
    expect($commands)->toContain('policies:premium-cover-sweep');
    $this->artisan('policies:premium-cover-sweep')->assertSuccessful();
});
