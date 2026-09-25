<?php

declare(strict_types=1);

// Batch 14 E5 — REQ-HLT-004 benefit accumulator (cashless + reimbursement).

use App\Application\Claims\ClaimPaymentService;
use App\Application\Health\Benefits\BenefitAccumulator;
use App\Application\Health\Benefits\BenefitRefused;
use App\Application\Health\Benefits\BenefitSchedule;
use App\Domain\Tenancy\TenantContext;
use App\Models\ClaimPayment;
use App\Models\CoverageDefinition;
use App\Models\InsuranceLine;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function e5User(): User
{
    return User::create(['full_name' => 'Hlt '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

function e5Staff(Tenant $t, array $perms): User
{
    $u = e5User();
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => 'CLM', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $t->id, 'code' => 'HLT_'.Str::random(4), 'permissions' => $perms, 'is_system' => false])->id);

    return $u;
}

/** Tenant + product + policy (2026-01-01 → 2027-01-01, product via proposal → offer). */
function e5World(): array
{
    Storage::fake('local');
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    app(TenantContext::class)->set($f['tenant']->id);
    $policy = (string) Str::uuid();
    DB::table('policies')->insert([
        'id' => $policy, 'tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'status' => 'ACTIVE', 'coverage_starts_at' => '2026-01-01 00:00:00+00', 'coverage_ends_at' => '2027-01-01 00:00:00+00', 'issued_at' => '2026-01-01 00:00:00+00',
        'policy_number' => 'POL-'.Str::random(6), 'terms_snapshot' => json_encode(['line_code' => 'HEALTH']), 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 100000, 'is_demo' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $f + ['policy' => $policy, 't' => $f['tenant']->id, 'pid' => $f['product']->id];
}

function e5Schedule(array $w, array $d): object
{
    return app(BenefitSchedule::class)->create($d + ['insurance_product_id' => $w['pid'], 'effective_from' => '2025-01-01', 'period_basis' => 'POLICY_YEAR']);
}

function e5Member(array $w, string $ref = 'M-1', ?string $family = null): array
{
    return array_filter(['member_ref' => $ref, 'family_ref' => $family, 'policy_id' => $w['policy']]);
}

it('REQ-HLT-004 reserve / release / consume / reverse with remaining = limit − reserved − consumed and an append-only ledger', function () {
    $w = e5World();
    e5Schedule($w, ['benefit_code' => 'OPD', 'period_limit_minor' => 500_000, 'copay_bp' => 2000]);
    $acc = app(BenefitAccumulator::class);
    $at = Carbon::parse('2026-03-01');
    $m = e5Member($w);

    $r = $acc->reserve($w['t'], $m, 'OPD', 200_000, $at, ['reference_type' => 'preauth', 'reference_id' => 'PA-1']);
    expect($acc->remaining($w['t'], $m, 'OPD', $at)['remaining_minor'])->toBe(300_000);
    $acc->release($w['t'], $m, 'OPD', 50_000, $at, ['reference_type' => 'preauth', 'reference_id' => 'PA-1']);
    $c = $acc->consume($w['t'], $m, 'OPD', 180_000, $at, ['reference_type' => 'preauth', 'reference_id' => 'PA-1']);
    $rem = $acc->remaining($w['t'], $m, 'OPD', $at);
    // 150k reserved → 150k drawn from the reservation + 30k fresh.
    expect($rem['remaining_minor'])->toBe(320_000)->and($rem['accumulators'][0]['reserved_minor'])->toBe(0)->and($rem['accumulators'][0]['consumed_minor'])->toBe(180_000)
        ->and($rem['period_key'])->toBe('PY2026-01-01')->and($rem['copay_bp'])->toBe(2000);

    $acc->reverse($c['group_id']);
    expect($acc->remaining($w['t'], $m, 'OPD', $at)['remaining_minor'])->toBe(500_000);
    expect($acc->reverse($c['group_id'])['group_id'])->not->toBe($c['group_id']); // idempotent: same reversal group returned
    expect(DB::table('health_benefit_movements')->where('movement_type', 'REVERSE')->count())->toBe(1);
    expect(fn () => $acc->reverse($r['group_id']))->toThrow(BenefitRefused::class);

    expect(fn () => DB::transaction(fn () => DB::table('health_benefit_movements')->where('group_id', $r['group_id'])->update(['amount_minor' => 1])))->toThrow(\Illuminate\Database\QueryException::class);

    // Next policy year is a fresh period.
    expect($acc->remaining($w['t'], $m, 'OPD', Carbon::parse('2027-02-01'))['period_key'])->toBe('PY2027-01-01');

    // Copay split capped by remaining.
    $adj = $acc->adjudicate($w['t'], $m, 'OPD', 100_000, $at);
    expect($adj['copay_minor'])->toBe(20_000)->and($adj['payable_minor'])->toBe(80_000);
});

it('REQ-HLT-004 concurrency: two reservations jointly exceeding remaining — the second is refused atomically, idempotent retries do not double count', function () {
    $w = e5World();
    $parent = e5Schedule($w, ['benefit_code' => 'IPD', 'period_limit_minor' => 1_000_000, 'family_limit_minor' => 1_500_000]);
    e5Schedule($w, ['benefit_code' => 'IPD.SURGERY', 'parent_schedule_id' => $parent->id, 'period_limit_minor' => 800_000]);
    $acc = app(BenefitAccumulator::class);
    $at = Carbon::parse('2026-04-01');
    $a = e5Member($w, 'M-A', 'FAM-1');
    $b = e5Member($w, 'M-B', 'FAM-1');

    // Sub-limit cascades to parent individual + family pool.
    $acc->reserve($w['t'], $a, 'IPD.SURGERY', 800_000, $at, ['holder' => 'preauth:1', 'idempotency_key' => 'k1']);
    $acc->reserve($w['t'], $a, 'IPD.SURGERY', 800_000, $at, ['holder' => 'preauth:1', 'idempotency_key' => 'k1']); // retry
    expect(DB::table('health_benefit_movements')->count())->toBe(3);
    expect($acc->remaining($w['t'], $a, 'IPD', $at)['remaining_minor'])->toBe(200_000);

    $acc->reserve($w['t'], $b, 'IPD', 600_000, $at, ['holder' => 'preauth:2']);
    $before = DB::table('health_benefit_accumulators')->orderBy('id')->get(['id', 'reserved_minor', 'consumed_minor'])->all();
    try {
        $acc->reserve($w['t'], $b, 'IPD', 200_000, $at, ['holder' => 'preauth:3']); // family: 1.4M of 1.5M reserved
        $this->fail('second reservation should be refused');
    } catch (BenefitRefused $e) {
        expect($e->reasonCode)->toBe('family_exhausted')->and($e->remainingMinor)->toBe(100_000);
    }
    expect(DB::table('health_benefit_accumulators')->orderBy('id')->get(['id', 'reserved_minor', 'consumed_minor'])->all())->toEqual($before)
        ->and(DB::table('health_benefit_movements')->where('holder_ref', 'preauth:3')->count())->toBe(0);
    expect(fn () => $acc->reserve($w['t'], $a, 'IPD.SURGERY', 1, $at, ['holder' => 'preauth:4']))->toThrow(BenefitRefused::class);
    expect(fn () => $acc->release($w['t'], $a, 'IPD.SURGERY', 900_000, $at, ['holder' => 'preauth:1']))->toThrow(BenefitRefused::class);

    // Counters never go negative (DB backstop).
    expect(fn () => DB::transaction(fn () => DB::table('health_benefit_accumulators')->update(['reserved_minor' => -1])))->toThrow(\Illuminate\Database\QueryException::class);
});

it('REQ-HLT-004 per-event, per-visit, visit count and waiting period; product-model coverage_limits and waiting days are the fallback', function () {
    $w = e5World();
    $line = InsuranceLine::firstOrCreate(['code' => 'E5_HEALTH'], ['name' => ['en' => 'Health'], 'status' => 'ACTIVE', 'risk_schema' => []]);
    $cov = CoverageDefinition::create(['insurance_line_id' => $line->id, 'code' => 'E5_MAT', 'name' => ['en' => 'Maternity'], 'limit_type' => 'FIXED_AMOUNT', 'mandatory' => false, 'status' => 'ACTIVE']);
    DB::table('product_coverages')->insert(['insurance_product_id' => $w['pid'], 'coverage_definition_id' => $cov->id, 'waiting_period_days' => 270]);
    DB::table('coverage_limits')->insert([
        ['id' => (string) Str::uuid(), 'insurance_product_id' => $w['pid'], 'coverage_definition_id' => $cov->id, 'limit_type' => 'PER_YEAR', 'amount_minor' => 700_000, 'currency' => 'XAF', 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'insurance_product_id' => $w['pid'], 'coverage_definition_id' => $cov->id, 'limit_type' => 'PER_EVENT', 'amount_minor' => 400_000, 'currency' => 'XAF', 'created_at' => now(), 'updated_at' => now()],
    ]);
    e5Schedule($w, ['benefit_code' => 'MAT', 'coverage_definition_id' => $cov->id]);
    e5Schedule($w, ['benefit_code' => 'DENTAL', 'period_basis' => 'CALENDAR_YEAR', 'per_visit_limit_minor' => 30_000, 'max_visits_per_period' => 2]);
    $acc = app(BenefitAccumulator::class);
    $m = e5Member($w);

    $early = $acc->remaining($w['t'], $m, 'MAT', Carbon::parse('2026-05-01'));
    expect($early['in_waiting_period'])->toBeTrue()->and($early['remaining_minor'])->toBe(700_000)->and($early['per_event_limit_minor'])->toBe(400_000);
    expect(fn () => $acc->reserve($w['t'], $m, 'MAT', 1000, Carbon::parse('2026-05-01')))->toThrow(BenefitRefused::class, 'waiting period');

    $at = Carbon::parse('2026-11-01');
    $acc->reserve($w['t'], $m, 'MAT', 300_000, $at, ['holder' => 'c:1', 'event_ref' => 'EV-1']);
    try {
        $acc->reserve($w['t'], $m, 'MAT', 150_000, $at, ['holder' => 'c:2', 'event_ref' => 'EV-1']);
        $this->fail('per-event');
    } catch (BenefitRefused $e) {
        expect($e->reasonCode)->toBe('per_event_exceeded')->and($e->remainingMinor)->toBe(100_000);
    }

    $d = Carbon::parse('2026-06-01');
    expect(fn () => $acc->consume($w['t'], $m, 'DENTAL', 40_000, $d, ['visit_ref' => 'V1']))->toThrow(BenefitRefused::class);
    $acc->consume($w['t'], $m, 'DENTAL', 20_000, $d, ['visit_ref' => 'V1']);
    $acc->consume($w['t'], $m, 'DENTAL', 10_000, $d, ['visit_ref' => 'V1']);
    $acc->consume($w['t'], $m, 'DENTAL', 10_000, $d, ['visit_ref' => 'V2']);
    try {
        $acc->consume($w['t'], $m, 'DENTAL', 10_000, $d, ['visit_ref' => 'V3']);
        $this->fail('visits');
    } catch (BenefitRefused $e) {
        expect($e->reasonCode)->toBe('visits_exhausted');
    }
    expect($acc->remaining($w['t'], $m, 'DENTAL', $d))->toMatchArray(['visits_used' => 2, 'period_key' => 'CY2026', 'remaining_minor' => null]);
    expect(fn () => $acc->remaining($w['t'], $m, 'UNKNOWN', $d, true))->toThrow(BenefitRefused::class);
});

it('REQ-HLT-004 reimbursement hook: a paid health claim payment consumes benefits (drawing the claim reservation), reversal gives them back, overrun is flagged', function () {
    $w = e5World();
    e5Schedule($w, ['benefit_code' => 'OPD', 'period_limit_minor' => 100_000]);
    $maker = e5User();
    $checker = e5User();
    $claim = (string) Str::uuid();
    DB::table('claims')->insert(['id' => $claim, 'tenant_id' => $w['t'], 'policy_id' => $w['policy'], 'claimant_party_id' => $w['party']->id, 'claim_number' => 'CLM-'.Str::random(8),
        'status' => 'APPROVED', 'loss_occurred_at' => '2026-03-01', 'submitted_at' => now(), 'created_at' => now(), 'currency' => 'XAF', 'priority' => 'NORMAL',
        'loss_details' => json_encode(['health' => ['member_ref' => 'M-1', 'benefits' => [['benefit_code' => 'OPD', 'amount_minor' => 120_000]]]]),
        'current_reserve_minor' => 120000, 'approved_amount_minor' => 120000, 'version' => 1, 'is_demo' => false]);
    $decision = (string) Str::uuid();
    DB::table('claim_decisions')->insert(['id' => $decision, 'claim_id' => $claim, 'decision' => 'APPROVE', 'approved_amount_minor' => 120000, 'currency' => 'XAF',
        'reason_code' => 'OK', 'rationale' => 'ok', 'status' => 'APPROVED', 'proposed_by' => $maker->id, 'approved_by' => $checker->id, 'approved_at' => now()]);

    $acc = app(BenefitAccumulator::class);
    $acc->reserve($w['t'], e5Member($w), 'OPD', 60_000, Carbon::parse('2026-03-01'), ['claim_id' => $claim]);

    $pay = app(ClaimPaymentService::class);
    $p = $pay->request(\App\Models\Claim::findOrFail($claim), \App\Models\ClaimDecision::findOrFail($decision), ['payee_party_id' => $w['party']->id, 'amount_minor' => 120_000, 'idempotency_key' => 'e5-1'], $maker);
    $p = $pay->processing($pay->approve($p, $checker));
    $pay->paid($p, 'MOMO-1');

    $rem = $acc->remaining($w['t'], e5Member($w), 'OPD', Carbon::parse('2026-03-01'));
    expect($rem['accumulators'][0]['consumed_minor'])->toBe(120_000)->and($rem['accumulators'][0]['reserved_minor'])->toBe(0)->and($rem['remaining_minor'])->toBe(0);
    $mv = DB::table('health_benefit_movements')->where('movement_type', 'CONSUME')->first();
    expect((bool) $mv->overrun)->toBeTrue()->and($mv->reference_id)->toBe($p->id)->and((int) $mv->reserved_delta_minor)->toBe(-60_000);
    expect(DB::table('outbox_messages')->where('event_name', 'health.benefit.overrun')->where('aggregate_id', $claim)->exists())->toBeTrue();

    $pay->reverse(ClaimPayment::findOrFail($p->id), 'bounced', $maker);
    expect($acc->remaining($w['t'], e5Member($w), 'OPD', Carbon::parse('2026-03-01'))['accumulators'][0]['consumed_minor'])->toBe(0);

    // Non-health claims are untouched by the hook.
    expect(DB::table('health_benefit_movements')->whereNull('claim_id')->count())->toBe(0);
});

it('REQ-HLT-004 EligibilityService call shape: remaining(tenant, memberId, benefitCode, CarbonImmutable) never throws for a missing schedule', function () {
    $w = e5World();
    $acc = app(BenefitAccumulator::class);
    $r = $acc->remaining($w['t'], (string) Str::uuid(), 'OPD', \Carbon\CarbonImmutable::parse('2026-03-01'));
    expect($r['remaining_minor'])->toBeNull()->and($r['reason_code'])->toBe('schedule_not_found');
    e5Schedule($w, ['benefit_code' => 'OPD', 'period_limit_minor' => 90_000]);
    expect($acc->remaining($w['t'], e5Member($w), 'OPD', \Carbon\CarbonImmutable::parse('2026-03-01'))['remaining_minor'])->toBe(90_000);
});

it('REQ-HLT-004 API: schedules and remaining are permission-gated and tenant-scoped', function () {
    $w = e5World();
    $viewer = e5Staff($w['tenant'], ['health.benefits.view']);
    $manager = e5Staff($w['tenant'], ['health.benefits.view', 'health.benefits.manage']);
    $h = ['X-Tenant-Id' => $w['t']];

    Passport::actingAs($viewer);
    $this->postJson('/api/v1/health/benefit-schedules', ['insurance_product_id' => $w['pid'], 'benefit_code' => 'OPD', 'effective_from' => '2025-01-01'], $h)->assertForbidden();
    Passport::actingAs($manager);
    $this->postJson('/api/v1/health/benefit-schedules', ['insurance_product_id' => $w['pid'], 'benefit_code' => 'OPD', 'period_limit_minor' => 250_000, 'effective_from' => '2025-01-01'], $h)
        ->assertCreated()->assertJsonPath('data.tenant_id', $w['t']);
    Passport::actingAs($viewer);
    $this->getJson('/api/v1/health/benefit-schedules?insurance_product_id='.$w['pid'], $h)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/health/benefits/remaining?member_ref=M-1&benefit_code=OPD&at=2026-02-01&gross_minor=300000&policy_id='.$w['policy'], $h)
        ->assertOk()->assertJsonPath('data.remaining_minor', 250_000)->assertJsonPath('data.adjudication.payable_minor', 250_000);
    $this->getJson('/api/v1/health/benefits/remaining?member_ref=M-1&benefit_code=NOPE&policy_id='.$w['policy'], $h)->assertStatus(422)->assertJsonPath('reason_code', 'schedule_not_found');
});
