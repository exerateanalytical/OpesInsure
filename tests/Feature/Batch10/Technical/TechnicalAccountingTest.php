<?php

declare(strict_types=1);

use App\Application\Ledger\Technical\ActuarialImportService;
use App\Application\Ledger\Technical\TechnicalAccountingService;
use App\Application\Ledger\Technical\UprPostingService;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function b109User(): User
{
    return User::create(['full_name' => 'Act '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

function b109Staff(Tenant $t, array $perms): User
{
    $u = b109User();
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => 'FIN', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $t->id, 'code' => 'ACT_'.Str::random(4), 'permissions' => $perms, 'is_system' => false])->id);

    return $u;
}

function b109Policy(array $f, string $line, int $premium, string $starts, string $ends, ?string $cancelledFrom = null): string
{
    $id = (string) Str::uuid();
    DB::table('policies')->insert([
        'id' => $id, 'tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'status' => $cancelledFrom ? 'CANCELLED' : 'ACTIVE', 'coverage_starts_at' => $starts, 'coverage_ends_at' => $ends, 'issued_at' => $starts,
        'terms_snapshot' => json_encode(['line_code' => $line]), 'version' => 1, 'currency' => 'XAF', 'premium_minor' => $premium, 'is_demo' => false,
        'created_at' => $starts, 'updated_at' => $starts,
    ]);
    if ($cancelledFrom) {
        DB::table('policy_versions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $f['tenant']->id, 'policy_id' => $id, 'version_no' => 2, 'kind' => 'CANCELLATION',
            'valid_from' => $cancelledFrom, 'recorded_at' => $cancelledFrom, 'schema_version' => 1, 'snapshot' => '{}', 'snapshot_hash' => str_repeat('0', 64)]);
    }

    return $id;
}

/** Fixture: AUTO policy 36 500 over 365 days + claim; MRH policy 3 650 cancelled after 29 days. */
function b109World(): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $actor = b109User();
    $checker = b109User();
    $p1 = b109Policy($f, 'AUTO', 36500, '2025-01-01 00:00:00+00', '2026-01-01 00:00:00+00');
    $p2 = b109Policy($f, 'MRH', 3650, '2025-02-01 00:00:00+00', '2026-02-01 00:00:00+00', '2025-03-02 00:00:00+00');
    $claim = (string) Str::uuid();
    DB::table('claims')->insert(['id' => $claim, 'tenant_id' => $f['tenant']->id, 'policy_id' => $p1, 'claim_number' => 'CLM-'.Str::random(8), 'status' => 'CLOSED',
        'loss_occurred_at' => '2025-02-08', 'loss_details' => '{}', 'submitted_at' => '2025-02-10 09:00:00+00', 'created_at' => '2025-02-10 09:00:00+00',
        'currency' => 'XAF', 'priority' => 'NORMAL', 'current_reserve_minor' => 12000, 'version' => 1, 'is_demo' => false, 'closed_at' => '2025-05-30 12:00:00+00']);
    foreach ([[0, 10000, '2025-02-15 10:00:00+00'], [10000, 12000, '2025-04-20 10:00:00+00']] as [$prev, $amt, $at]) {
        DB::table('claim_reserve_changes')->insert(['id' => (string) Str::uuid(), 'claim_id' => $claim, 'previous_amount_minor' => $prev, 'requested_amount_minor' => $amt,
            'currency' => 'XAF', 'status' => 'APPROVED', 'reason_code' => 'ASSESSMENT', 'requested_by' => $actor->id, 'approved_by' => $checker->id, 'approved_at' => $at, 'created_at' => $at]);
    }
    // A rejected reserve change is ignored.
    DB::table('claim_reserve_changes')->insert(['id' => (string) Str::uuid(), 'claim_id' => $claim, 'previous_amount_minor' => 10000, 'requested_amount_minor' => 99000,
        'currency' => 'XAF', 'status' => 'REJECTED', 'reason_code' => 'X', 'requested_by' => $actor->id, 'created_at' => '2025-03-01']);
    $decision = (string) Str::uuid();
    DB::table('claim_decisions')->insert(['id' => $decision, 'claim_id' => $claim, 'decision' => 'APPROVE', 'approved_amount_minor' => 12000, 'currency' => 'XAF',
        'reason_code' => 'OK', 'rationale' => 'ok', 'status' => 'APPROVED', 'proposed_by' => $actor->id, 'approved_by' => $checker->id, 'approved_at' => '2025-03-01']);
    foreach ([[4000, 'PAID', '2025-03-10 10:00:00+00'], [8000, 'PAID', '2025-05-25 10:00:00+00'], [5000, 'PENDING_APPROVAL', null]] as [$amt, $st, $at]) {
        DB::table('claim_payments')->insert(['id' => (string) Str::uuid(), 'claim_id' => $claim, 'claim_decision_id' => $decision, 'payee_party_id' => $f['party']->id,
            'amount_minor' => $amt, 'currency' => 'XAF', 'status' => $st, 'idempotency_key' => Str::uuid(), 'attempt_count' => 0, 'requested_by' => $actor->id, 'approved_by' => $at ? $checker->id : null, 'paid_at' => $at]);
    }

    return $f + ['p1' => $p1, 'p2' => $p2, 'claim' => $claim];
}

function b109Row(array $rows, string $line): array
{
    return collect($rows)->firstWhere('line_code', $line) ?? [];
}

it('REQ-ACC-004 computes written / earned (pro-rata daily) / UPR per carrier and line with the UPR roll-forward identity', function () {
    $w = b109World();
    $svc = app(TechnicalAccountingService::class);
    $t = $w['tenant']->id;
    $q1 = $svc->premiums($t, CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-03-31'));
    $auto = b109Row($q1, 'AUTO');
    expect($auto)->toMatchArray(['carrier_id' => $w['carrier']->id, 'currency' => 'XAF', 'written_minor' => 36500, 'earned_minor' => 9000, 'upr_opening_minor' => 0, 'upr_closing_minor' => 27500, 'cancelled_release_minor' => 0]);
    // Cancelled MRH: 29 days earned, the unearned rest released (not earned) at cancellation.
    expect(b109Row($q1, 'MRH'))->toMatchArray(['written_minor' => 3650, 'earned_minor' => 290, 'upr_closing_minor' => 0, 'cancelled_release_minor' => 3360]);

    $q2 = $svc->premiums($t, CarbonImmutable::parse('2025-04-01'), CarbonImmutable::parse('2025-06-30'));
    expect(b109Row($q2, 'AUTO'))->toMatchArray(['written_minor' => 0, 'earned_minor' => 9100, 'upr_opening_minor' => 27500, 'upr_closing_minor' => 18400, 'upr_movement_minor' => -9100])
        ->and(b109Row($q2, 'MRH'))->toBe([]);

    // Full-term earning sums to premium exactly, whatever the slicing.
    $total = 0;
    foreach (range(1, 12) as $m) {
        $from = CarbonImmutable::create(2025, $m, 1);
        $total += b109Row($svc->premiums($t, $from, $from->endOfMonth()), 'AUTO')['earned_minor'] ?? 0;
    }
    expect($total)->toBe(36500)
        ->and($svc->premiums($t, CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-03-31'), null, 'MRH'))->toHaveCount(1)
        ->and($svc->premiums($t, CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-03-31'), (string) Str::uuid()))->toBe([]);
});

it('REQ-ACC-004 derives claims paid / outstanding / incurred from reserves and payments', function () {
    $w = b109World();
    $svc = app(TechnicalAccountingService::class);
    $q1 = b109Row($svc->claims($w['tenant']->id, CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-03-31')), 'AUTO');
    expect($q1)->toMatchArray(['claims' => 1, 'reported' => 1, 'paid_minor' => 4000, 'outstanding_opening_minor' => 0, 'outstanding_closing_minor' => 6000, 'incurred_minor' => 10000]);
    $q2 = b109Row($svc->claims($w['tenant']->id, CarbonImmutable::parse('2025-04-01'), CarbonImmutable::parse('2025-06-30')), 'AUTO');
    expect($q2)->toMatchArray(['reported' => 0, 'paid_minor' => 8000, 'outstanding_opening_minor' => 6000, 'outstanding_closing_minor' => 0, 'incurred_minor' => 2000]);
    // Read-only: nothing on policies / claims changed.
    expect((int) DB::table('claims')->where('id', $w['claim'])->value('current_reserve_minor'))->toBe(12000);
});

it('REQ-ACC-004 imports IBNR as versioned maker-checker batches and never computes it', function () {
    $w = b109World();
    $t = $w['tenant']->id;
    $svc = app(ActuarialImportService::class);
    $maker = b109User();
    $checker = b109User();
    $v1 = $svc->import($t, 'IBNR', '2025-03-31', 'actuary-q1.csv', [['carrier_id' => $w['carrier']->id, 'line_code' => 'AUTO', 'metric' => 'ibnr', 'amount_minor' => 1500, 'currency' => 'XAF']], $maker->id);
    expect($v1->version)->toBe(1)->and($v1->status)->toBe('PENDING_APPROVAL');
    $summary = fn () => b109Row(app(TechnicalAccountingService::class)->summary($t, CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-03-31')), 'AUTO');
    expect($summary()['ibnr_minor'])->toBeNull();
    expect(fn () => $svc->approve($t, $v1->id, $maker->id))->toThrow(ValidationException::class);
    $svc->approve($t, $v1->id, $checker->id);
    expect($summary())->toMatchArray(['ibnr_minor' => 1500, 'earned_minor' => 9000, 'incurred_minor' => 10000, 'loss_ratio_bp' => 12777]);

    $v2 = $svc->import($t, 'IBNR', '2025-03-31', 'actuary-q1-rev.csv', [['carrier_id' => $w['carrier']->id, 'line_code' => 'AUTO', 'metric' => 'IBNR', 'amount_minor' => 2000, 'currency' => 'XAF']], $maker->id);
    expect($v2->version)->toBe(2);
    $svc->approve($t, $v2->id, $checker->id);
    expect(DB::table('technical_actuarial_imports')->where('id', $v1->id)->value('status'))->toBe('SUPERSEDED')
        ->and($summary()['ibnr_minor'])->toBe(2000)
        ->and(DB::table('outbox_messages')->where('event_name', 'technical.actuarial_import.approved')->count())->toBe(2);
    expect(fn () => $svc->approve($t, $v2->id, $checker->id))->toThrow(ValidationException::class);
});

it('REQ-ACC-004 posts the period-end UPR movement once through FinancialPostingService', function () {
    $w = b109World();
    $t = $w['tenant']->id;
    $admin = b109User();
    $acc = fn ($code, $type) => tap((string) Str::uuid(), fn ($id) => DB::table('ledger_accounts')->insert(['id' => $id, 'tenant_id' => null, 'code' => $code, 'name' => $code, 'type' => $type, 'currency' => 'XAF', 'status' => 'ACTIVE']));
    $expense = $acc('UPR-CHG-'.Str::random(4), 'EXPENSE');
    $liability = $acc('UPR-'.Str::random(4), 'LIABILITY');
    DB::table('financial_posting_profiles')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'event_type' => 'technical.upr.movement', 'currency' => 'XAF',
        'debit_account_id' => $expense, 'credit_account_id' => $liability, 'status' => 'APPROVED', 'created_by' => $admin->id]);

    $rows = app(UprPostingService::class)->post($t, CarbonImmutable::parse('2025-03-31'), $admin->id, 'corr-1');
    expect($rows)->toHaveCount(1)->and((int) $rows[0]->upr_minor)->toBe(27500)->and((int) $rows[0]->movement_minor)->toBe(27500)->and($rows[0]->journal_id)->not->toBeNull();
    expect(DB::table('journals')->where(['reference_type' => 'technical.upr.movement', 'reference_id' => $rows[0]->id])->value('status'))->toBe('POSTED');
    $again = app(UprPostingService::class)->post($t, CarbonImmutable::parse('2025-03-31'), $admin->id, 'corr-2');
    expect($again[0]->id)->toBe($rows[0]->id)->and(DB::table('journals')->where('reference_type', 'technical.upr.movement')->count())->toBe(1);
});

it('REQ-ACC-004 serves JSON and CSV reports and actuarial import APIs behind permissions with tenant isolation', function () {
    $w = b109World();
    $t = $w['tenant'];
    $h = ['X-Tenant-Id' => $t->id];
    Passport::actingAs(b109Staff($t, []));
    $this->getJson('/api/v1/finance/technical/reports/premiums?from=2025-01-01&to=2025-03-31', $h)->assertForbidden();

    Passport::actingAs(b109Staff($t, ['technical_accounting.read']));
    $this->getJson('/api/v1/finance/technical/reports/premiums?from=2025-01-01&to=2025-03-31&line_code=AUTO', $h)->assertOk()
        ->assertJsonPath('data.0.earned_minor', 9000)->assertJsonPath('meta.basis', 'pro_rata_daily');
    $this->getJson('/api/v1/finance/technical/reports/claims?from=2025-01-01&to=2025-03-31', $h)->assertOk()->assertJsonPath('data.0.incurred_minor', 10000);
    $this->getJson('/api/v1/finance/technical/reports/summary?from=2025-03-31&to=2025-01-01', $h)->assertUnprocessable();
    $csv = $this->get('/api/v1/finance/technical/reports/summary?from=2025-01-01&to=2025-03-31&format=csv', $h);
    $csv->assertOk();
    $body = $csv->streamedContent();
    expect($body)->toContain('carrier_id,line_code,currency')->toContain('AUTO')->toContain('27500');
    $this->postJson('/api/v1/finance/technical/actuarial-imports', [], $h)->assertForbidden();

    $maker = b109Staff($t, ['technical_accounting.actuarial.import', 'technical_accounting.read']);
    Passport::actingAs($maker);
    $id = $this->postJson('/api/v1/finance/technical/actuarial-imports', ['kind' => 'LIFE_MATH_RESERVE', 'period_end' => '2025-03-31', 'source' => 'carrier-life-engine',
        'values' => [['carrier_id' => $w['carrier']->id, 'line_code' => 'LIFE', 'metric' => 'MATH_RESERVE', 'amount_minor' => 555000, 'currency' => 'XAF']]], $h)
        ->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');
    $this->getJson("/api/v1/finance/technical/actuarial-imports/{$id}", $h)->assertOk()->assertJsonPath('data.values.0.amount_minor', 555000);

    Passport::actingAs(b109Staff($t, ['technical_accounting.actuarial.approve']));
    $this->postJson("/api/v1/finance/technical/actuarial-imports/{$id}/approve", [], $h)->assertOk()->assertJsonPath('data.status', 'APPROVED');

    // Another tenant sees nothing.
    $o = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999))['tenant'];
    Passport::actingAs(b109Staff($o, ['technical_accounting.read']));
    $this->getJson('/api/v1/finance/technical/reports/summary?from=2025-01-01&to=2025-03-31', ['X-Tenant-Id' => $o->id])->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/finance/technical/actuarial-imports/{$id}", ['X-Tenant-Id' => $o->id])->assertNotFound();
});
