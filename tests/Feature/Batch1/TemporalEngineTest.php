<?php

declare(strict_types=1);

use App\Application\Policies\CancellationCalculator;
use App\Application\Temporal\BusinessCalendar;
use App\Application\Temporal\BusinessTime;
use App\Application\Temporal\ReferenceDateResolver;
use App\Application\Temporal\ReferenceInstant;
use App\Application\Temporal\TemporalResolutionException;
use App\Application\Temporal\VersionResolver;
use App\Domain\Shared\Clock\Clock;
use App\Domain\Shared\Clock\FrozenClock;
use App\Domain\Shared\Clock\SystemClock;
use App\Models\Policy;
use App\Models\User;
use App\Providers\TemporalServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(TemporalServiceProvider::class);
    $this->clock = new FrozenClock('2026-09-24T10:00:00+01:00');
    $this->app->instance(Clock::class, $this->clock);
    $this->user = User::factory()->create();
});

function cancelRule(array $o): string
{
    $id = (string) Str::uuid();
    DB::table('cancellation_rule_versions')->insert(array_merge([
        'id' => $id, 'line_code' => 'MOTOR', 'version' => 1, 'status' => 'APPROVED', 'basis' => 'PRO_RATA',
        'short_rate_basis_points' => 10000, 'admin_fee_minor' => 0, 'effective_from' => '2026-01-01', 'effective_until' => null,
        'created_by' => test()->user->id, 'created_at' => now(), 'updated_at' => now(),
    ], $o));

    return $id;
}

function policyFor(string $line): Policy
{
    $quote = new \App\Models\Quote(['line_code' => $line]);
    $quote->line_code = $line;
    $offer = (new \App\Models\QuoteOffer)->setRelation('quote', $quote);
    $proposal = (new \App\Models\Proposal)->setRelation('offer', $offer);
    $p = new Policy;
    $p->coverage_starts_at = CarbonImmutable::parse('2026-01-01 00:00', 'Africa/Douala');
    $p->coverage_ends_at = CarbonImmutable::parse('2026-12-31 00:00', 'Africa/Douala');
    $p->premium_minor = 364000;

    return $p->setRelation('proposal', $proposal);
}

it('REQ-TMP-001 binds a system clock and lets tests freeze and advance time', function () {
    expect((new SystemClock('Africa/Douala'))->now()->getTimezone()->getName())->toBe('Africa/Douala');
    $c = new FrozenClock('2026-12-31T23:30:00Z');
    expect($c->now()->toIso8601String())->toBe('2027-01-01T00:30:00+01:00')
        ->and($c->today()->toDateString())->toBe('2027-01-01')
        ->and($c->today('UTC')->toDateString())->toBe('2026-12-31');
    $c->advance('P1D');
    expect($c->today()->toDateString())->toBe('2027-01-02');
    expect(app(Clock::class))->toBe($this->clock);
});

it('REQ-TMP-001 applies business-date and timezone rules', function () {
    expect(BusinessTime::businessDate('2026-03-31T23:30:00Z'))->toBe('2026-04-01')
        ->and(BusinessTime::parse('2026-03-01')->toIso8601String())->toBe('2026-03-01T00:00:00+01:00')
        ->and(BusinessTime::withinDays('2026-06-30T12:00:00+01:00', '2026-01-01', '2026-06-30'))->toBeTrue()
        ->and(BusinessTime::withinDays('2026-06-30T23:30:00Z', '2026-01-01', '2026-06-30'))->toBeFalse();
});

it('REQ-TMP-001 resolves day-granularity versions closed-closed and never falls back to latest', function () {
    $v1 = cancelRule(['version' => 1, 'effective_from' => '2026-01-01', 'effective_until' => '2026-06-30']);
    $v2 = cancelRule(['version' => 2, 'effective_from' => '2026-07-01']);
    cancelRule(['version' => 3, 'status' => 'DRAFT', 'effective_from' => '2026-01-01']);
    $r = app(VersionResolver::class);

    expect($r->resolve('cancellation_rule', ['line_code' => 'MOTOR'], ReferenceInstant::at('2026-06-30 18:00'))->id)->toBe($v1)
        ->and($r->resolve('cancellation_rule', ['line_code' => 'MOTOR'], ReferenceInstant::at('2026-07-01 00:00'))->id)->toBe($v2)
        // 23:30 UTC on 30 June is already 1 July in Douala.
        ->and($r->resolve('cancellation_rule', ['line_code' => 'MOTOR'], ReferenceInstant::at('2026-06-30T23:30:00Z'))->id)->toBe($v2);

    expect(fn () => $r->resolve('cancellation_rule', ['line_code' => 'MOTOR'], ReferenceInstant::at('2025-12-31')))
        ->toThrow(TemporalResolutionException::class, 'TEMPORAL_NO_VERSION');
    cancelRule(['version' => 4, 'effective_from' => '2026-08-01']);
    expect(fn () => $r->resolve('cancellation_rule', ['line_code' => 'MOTOR'], ReferenceInstant::at('2026-08-15')))
        ->toThrow(TemporalResolutionException::class, 'TEMPORAL_AMBIGUOUS');
    expect(fn () => $r->resolve('cancellation_rule', ['line' => 'MOTOR'], ReferenceInstant::at('2026-08-15')))
        ->toThrow(InvalidArgumentException::class);
});

it('REQ-TMP-001 derives the reference instant from the seeded operation rule', function () {
    $rd = app(ReferenceDateResolver::class);
    $cancel = $rd->for('CANCEL', ['effective_at' => '2026-05-10 09:00'], 'cancellation_rule', 'TERMS');
    expect($cancel->anchor)->toBe('EFFECTIVE_AT')->and($cancel->businessDate())->toBe('2026-05-10');
    $claim = $rd->for('CLAIM_FNOL', ['loss_occurred_at' => '2026-02-03T08:00:00+01:00'], 'TERMS');
    expect($claim->anchor)->toBe('LOSS_OCCURRED_AT')->and($claim->businessDate())->toBe('2026-02-03');
    $auth = $rd->for('ISSUE', [], 'insurer_authorization', 'AUTHORITY');
    expect($auth->anchor)->toBe('REQUEST_AT')->and($auth->referenceAt->equalTo($this->clock->now()))->toBeTrue();
    expect(fn () => $rd->for('CANCEL', [], 'TERMS'))->toThrow(TemporalResolutionException::class, 'TEMPORAL_NO_ANCHOR');
    expect(fn () => $rd->for('NOPE', [], 'TERMS'))->toThrow(TemporalResolutionException::class, 'TEMPORAL_NO_RULE');
});

it('REQ-TMP-002 replays bitemporal knowledge as of a past recorded instant', function () {
    DB::table('parties')->insert(['id' => $pid = (string) Str::uuid(), 'type' => 'ORGANIZATION', 'display_name' => 'Temporal Test Carrier', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('carriers')->insert(['id' => $cid = (string) Str::uuid(), 'party_id' => $pid, 'cima_code' => 'TMP-TEST', 'created_at' => now(), 'updated_at' => now()]);
    // What we knew on 1 Feb: authorized from 1 Jan. On 1 Mar we learned it actually started 15 Jan (correction).
    $old = (string) Str::uuid();
    $new = (string) Str::uuid();
    $base = ['carrier_id' => $cid, 'branch' => 'IARD', 'status' => 'AUTHORIZED', 'source_authority' => 'TEST', 'data_origin' => 'TEST', 'created_at' => now(), 'updated_at' => now()];
    DB::table('insurer_authorizations')->insert([...$base, 'id' => $old, 'reference_year' => 2025, 'effective_from' => '2026-01-01',
        'recorded_at' => '2026-02-01T00:00:00+01:00', 'superseded_at' => '2026-03-01T00:00:00+01:00']);
    DB::table('insurer_authorizations')->insert([...$base, 'id' => $new, 'reference_year' => 2026, 'effective_from' => '2026-01-15',
        'recorded_at' => '2026-03-01T00:00:00+01:00']);
    $r = app(VersionResolver::class);
    $key = ['carrier_id' => $cid, 'branch' => 'IARD'];
    $at = ReferenceInstant::at('2026-01-10');

    expect($r->resolve('insurer_authorization', $key, $at, CarbonImmutable::parse('2026-02-15T00:00:00+01:00'))->id)->toBe($old);
    expect(fn () => $r->resolve('insurer_authorization', $key, $at))->toThrow(TemporalResolutionException::class, 'TEMPORAL_NO_VERSION');
    expect($r->resolve('insurer_authorization', $key, ReferenceInstant::at('2026-01-20'))->id)->toBe($new);
    expect(fn () => $r->resolve('insurer_authorization', $key, $at, CarbonImmutable::parse('2026-01-15')))->toThrow(TemporalResolutionException::class, 'TEMPORAL_NO_VERSION');
});

it('REQ-TMP-002 adds recorded_at/superseded_at to tariffs, products and authorizations', function () {
    foreach (['tariff_versions', 'insurance_products', 'insurer_authorizations'] as $t) {
        expect(\Illuminate\Support\Facades\Schema::hasColumns($t, ['recorded_at', 'superseded_at']))->toBeTrue();
    }
});

it('REQ-TMP-001 persists append-only transaction_resolved_versions', function () {
    $v = cancelRule([]);
    $subject = new class extends \Illuminate\Database\Eloquent\Model {
        protected $table = 'policies';
        public $incrementing = false;
        protected $keyType = 'string';
    };
    $subject->forceFill(['id' => (string) Str::uuid(), 'effective_at' => '2026-04-01 10:00']);
    $set = app(VersionResolver::class)->resolveSet('CANCEL', $subject, ['cancellation_rule' => ['line_code' => 'MOTOR']]);
    expect($set->versions[0]->id)->toBe($v)->and($set->toEngineResult()['engine'])->toBe('TEMPORAL')
        ->and($set->toEngineResult()['resolved_versions'])->toBe(['cancellation_rule' => $v]);
    $row = DB::table('transaction_resolved_versions')->where('id', $set->id)->first();
    expect($row->versions_hash)->toBe($set->versionsHash)->and($row->operation)->toBe('CANCEL');
    expect(fn () => DB::table('transaction_resolved_versions')->where('id', $set->id)->update(['operation' => 'X']))->toThrow(\Illuminate\Database\QueryException::class);
    expect(fn () => DB::table('transaction_resolved_versions')->where('id', $set->id)->delete())->toThrow(\Illuminate\Database\QueryException::class);
});

it('REQ-TMP-001 CancellationCalculator resolves its rule through the temporal engine', function () {
    cancelRule(['version' => 1, 'effective_from' => '2026-01-01', 'effective_until' => '2026-06-30', 'basis' => 'PRO_RATA']);
    $short = cancelRule(['version' => 2, 'effective_from' => '2026-07-01', 'basis' => 'SHORT_RATE', 'short_rate_basis_points' => 5000, 'admin_fee_minor' => 1000]);
    $calc = app(CancellationCalculator::class);
    $p = policyFor('MOTOR');

    $early = $calc->calculate($p, CarbonImmutable::parse('2026-03-01', 'Africa/Douala'));
    expect($early['basis'])->toBe('PRO_RATA')->and($early['unused_days'])->toEqual(305)->and($early['refund_minor'])->toBe(305000);
    $late = $calc->calculate($p, CarbonImmutable::parse('2026-07-01', 'Africa/Douala'));
    expect($late['rule_id'])->toBe($short)->and($late['refund_minor'])->toBe((int) round(183000 * 0.5) - 1000);

    expect(fn () => $calc->calculate(policyFor('HEALTH'), CarbonImmutable::parse('2026-03-01', 'Africa/Douala')))->toThrow(ValidationException::class);
    expect(fn () => $calc->calculate($p, CarbonImmutable::parse('2027-03-01', 'Africa/Douala')))->toThrow(ValidationException::class);
});

it('REQ-TMP-001 business calendar skips weekends and table holidays', function () {
    DB::table('business_calendars')->insert(['id' => (string) Str::uuid(), 'jurisdiction' => 'CM', 'year' => 2026, 'holidays' => json_encode(['2026-05-20']), 'timezone' => 'Africa/Douala', 'created_at' => now(), 'updated_at' => now()]);
    $cal = app(BusinessCalendar::class);
    expect($cal->isBusinessDay('2026-05-20'))->toBeFalse()
        ->and($cal->isBusinessDay('2026-05-23'))->toBeFalse()
        ->and($cal->isBusinessDay('2026-05-21'))->toBeTrue()
        ->and($cal->addBusinessDays('2026-05-19', 2)->toDateString())->toBe('2026-05-22')
        ->and($cal->addBusinessDays('2026-05-22', 1)->toDateString())->toBe('2026-05-25');
});

it('REQ-TMP-001 INV-1.1 temporal engine code never reads now() directly', function () {
    $files = [base_path('app/Application/Policies/CancellationCalculator.php'), ...glob(base_path('app/Application/Temporal/*.php')), ...glob(base_path('app/Domain/Shared/Clock/*.php'))];
    foreach ($files as $f) {
        $src = preg_replace('/(?:\$this->clock|\$this|\$c|clock\(\))->now\(\)|function now\(\)|CarbonImmutable::now\(\$this->timezone\)/', '', file_get_contents($f));
        expect(preg_match('/\bnow\(\)|Carbon::now|CarbonImmutable::now|Date::now/', $src))->toBe(0, $f);
    }
});

it('REQ-TMP-001 diagnostic resolve endpoint requires authentication', function () {
    $this->getJson('/api/v1/admin/temporal/resolve?artifact_type=cancellation_rule&key[line_code]=MOTOR&at=2026-03-01')->assertUnauthorized();
});
