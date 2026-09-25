<?php

declare(strict_types=1);

use App\Application\Finance\Allocations\AllocationRuleService;
use App\Application\Finance\Allocations\AllocationService;
use App\Application\Finance\Allocations\ObligationGateway;
use App\Application\Finance\PremiumStatus\PremiumComponentService;
use App\Application\Finance\PremiumStatus\PremiumStatusReadModel;
use App\Models\Policy;
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

const B92_ALL = ['payments.allocations.read', 'payments.allocations.manage', 'payments.allocations.reverse', 'finance.allocation_rules.manage',
    'premium_status.read', 'premium_components.manage', 'premium_components.close'];

function b92Staff(Tenant $t, array $perms = B92_ALL): User
{
    $u = User::create(['full_name' => 'Finance Ops', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => 'FIN_OPS', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $t->id, 'code' => 'FIN_OPS_'.Str::random(4), 'permissions' => $perms, 'is_system' => false])->id);

    return $u;
}

/** Policy with premium 90 000 + tax 5 000 + fee 5 000 XAF and a SUCCEEDED payment. */
function b92Setup(int $paid = 100000): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $t = $f['tenant'];
    $payment = makeMobileTestPayment($f['proposal'], $t, ['amount_minor' => $paid]);
    $policy = makeMobileTestPolicy($f['proposal'], $t, $f['carrier']->id, $f['party']->id, ['policy_number' => 'POL-B92-'.Str::random(5),
        'coverage_starts_at' => now()->subDays(5), 'terms_snapshot' => ['premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000]]);

    return ['tenant' => $t, 'payment' => $payment, 'policy' => $policy, 'proposal' => $f['proposal']];
}

function b92H(Tenant $t, ?string $key = null): array
{
    return ['X-Tenant-Id' => $t->id, 'Idempotency-Key' => $key ?? (string) Str::uuid()];
}

it('REQ-PAY-005 snapshots components and derives premium status separately from payment status', function () {
    $s = b92Setup();
    $svc = app(PremiumComponentService::class);
    $svc->captureFromTerms($s['policy'], null);
    $svc->captureFromTerms($s['policy'], null); // idempotent
    expect(DB::table('premium_components')->where('policy_id', $s['policy']->id)->count())->toBe(4);

    $view = app(PremiumStatusReadModel::class)->forPolicy($s['policy']);
    // Payment is SUCCEEDED but nothing is allocated: premium is still owed (and past due → OVERDUE).
    expect($view['premium_status'])->toBe('OVERDUE')->and($view['due_minor'])->toBe(100000)->and($view['outstanding_minor'])->toBe(0 + 100000);
    expect(PremiumStatusReadModel::derive(100, 0, 0, null, now()->addDay()->toIso8601String(), CarbonImmutable::now()))->toBe('NOT_DUE')
        ->and(PremiumStatusReadModel::derive(100, 0, 0, null, null, CarbonImmutable::now()))->toBe('DUE');

    expect(fn () => $svc->record($s['policy'], [['line_key' => 'TERMS:TAX', 'component' => 'TAX', 'amount_minor' => 1]], 'MANUAL', null))->toThrow(ValidationException::class);
});

it('REQ-PAY-004 allocates oldest-due-first with taxes and fees before premium, idempotently, never over-allocating', function () {
    $s = b92Setup(60000);
    $t = $s['tenant'];
    app(PremiumComponentService::class)->captureFromTerms($s['policy'], null);
    Passport::actingAs(b92Staff($t));
    $url = "/api/v1/payments/{$s['payment']->id}/allocations";
    $key = 'alloc-'.Str::uuid();

    $run = $this->postJson($url, [], b92H($t, $key))->assertStatus(201)->json('data');
    expect($run['rule_version'])->toBe(0)->and($run['allocated_minor'])->toBe(60000)
        ->and(collect($run['lines'])->pluck('target_category')->all())->toBe(['TAX', 'FEE', 'PREMIUM'])
        ->and(collect($run['lines'])->pluck('amount_minor')->all())->toBe([5000, 5000, 50000]);

    // Replay: same run, no new rows.
    $this->postJson($url, [], b92H($t, $key))->assertOk()->assertJsonPath('data.id', $run['id']);
    expect(DB::table('payment_allocations')->count())->toBe(3)->and(DB::table('outbox_messages')->where('event_name', 'payment.allocated')->count())->toBe(1);

    // Payment fully used: any further allocation is refused.
    $this->postJson($url, ['amount_minor' => 1], b92H($t))->assertStatus(422);
    $this->getJson($url, b92H($t))->assertOk()->assertJsonPath('data.unallocated_minor', 0);

    $status = $this->getJson("/api/v1/policies/{$s['policy']->id}/premium-status", b92H($t))->assertOk()->json('data');
    expect($status['premium_status'])->toBe('OVERDUE')->and($status['paid_minor'])->toBe(60000)->and($status['outstanding_minor'])->toBe(40000);

    // Second payment settles the rest → PAID; a component can never be over-allocated.
    $p2 = makeMobileTestPayment($s['proposal'], $t, ['amount_minor' => 50000]);
    $run2 = app(AllocationService::class)->allocate($t->id, $p2->id, $s['policy']->id, [], null, 'k2', null);
    expect($run2['allocated_minor'])->toBe(40000);
    expect(app(PremiumStatusReadModel::class)->forPolicy($s['policy'])['premium_status'])->toBe('PAID');
    expect(fn () => app(AllocationService::class)->allocate($t->id, $p2->id, $s['policy']->id, [], null, 'k3', null))->toThrow(ValidationException::class);
});

it('REQ-PAY-004 reverses with reversal rows only; refunds and write-offs drive premium status', function () {
    $s = b92Setup();
    $t = $s['tenant'];
    app(PremiumComponentService::class)->captureFromTerms($s['policy'], null);
    $alloc = app(AllocationService::class);
    $run = $alloc->allocate($t->id, $s['payment']->id, null, [], null, 'r1', null);
    expect($run['policy_id'])->toBe($s['policy']->id);
    expect(app(PremiumStatusReadModel::class)->forPolicy($s['policy'])['premium_status'])->toBe('PAID');

    Passport::actingAs(b92Staff($t));
    $this->postJson("/api/v1/payment-allocation-runs/{$run['id']}/reverse", ['reason_code' => 'REFUND', 'reason' => 'Customer refund'], b92H($t))
        ->assertOk()->assertJsonPath('data.status', 'REVERSED');
    $this->postJson("/api/v1/payment-allocation-runs/{$run['id']}/reverse", ['reason_code' => 'REFUND', 'reason' => 'again'], b92H($t))->assertOk();
    expect(DB::table('payment_allocations')->count())->toBe(6)
        ->and((int) DB::table('payment_allocations')->sum('amount_minor'))->toBe(0)
        ->and(DB::table('outbox_messages')->where('event_name', 'payment.allocation.reversed')->count())->toBe(1);
    expect(app(PremiumStatusReadModel::class)->forPolicy($s['policy'])['premium_status'])->toBe('REFUNDED');

    // Append-only at the database level.
    expect(fn () => DB::transaction(fn () => DB::table('payment_allocations')->delete()))->toThrow(\Illuminate\Database\QueryException::class);

    // Money is free again: partial allocation, then write off the remainder.
    $alloc->allocate($t->id, $s['payment']->id, null, [], 10000, 'r2', null);
    $prem = DB::table('premium_components')->where('policy_id', $s['policy']->id)->where('component', 'NET_PREMIUM')->first();
    $view = $this->postJson("/api/v1/premium-components/{$prem->id}/close", ['closure' => 'WRITTEN_OFF', 'reason' => 'Uncollectable'], b92H($t))->assertOk()->json('data');
    expect($view['premium_status'])->toBe('PARTIALLY_REFUNDED'); // refund history still dominates
    expect(DB::table('outbox_messages')->where('event_name', 'finance.premium_component.written_off')->count())->toBe(1);
});

it('REQ-PAY-004 honours a versioned per-tenant rule and settles linked obligations through the gateway', function () {
    $s = b92Setup(30000);
    $t = $s['tenant'];
    $obl = (string) Str::uuid();
    $calls = new ArrayObject;
    app()->instance(ObligationGateway::class, new class($calls) implements ObligationGateway
    {
        public function __construct(private ArrayObject $calls) {}

        public function openReceivables(string $tenantId, array $ids, ?string $policyId): array
        {
            return [];
        }

        public function settle(string $obligationId, int $amountMinor, string $reference): void
        {
            $this->calls[] = [$obligationId, $amountMinor];
        }
    });
    app(PremiumComponentService::class)->record($s['policy'], [
        ['line_key' => 'I1:PREM', 'component' => 'NET_PREMIUM', 'amount_minor' => 20000, 'due_at' => '2026-01-01T00:00:00Z', 'financial_obligation_id' => $obl],
        ['line_key' => 'I1:TAX', 'component' => 'TAX', 'amount_minor' => 2000, 'due_at' => '2026-02-01T00:00:00Z'],
        ['line_key' => 'I2:PREM', 'component' => 'NET_PREMIUM', 'amount_minor' => 20000, 'due_at' => '2026-03-01T00:00:00Z'],
    ], 'MANUAL', null);

    Passport::actingAs(b92Staff($t));
    $this->postJson('/api/v1/finance/allocation-rule', ['strategy' => 'BOGUS', 'priority' => ['TAX'], 'reason' => 'x'], b92H($t))->assertStatus(422);
    $this->postJson('/api/v1/finance/allocation-rule', ['strategy' => 'PRIORITY_FIRST', 'priority' => ['TAX', 'PREMIUM'], 'reason' => 'Carrier policy'], b92H($t))
        ->assertStatus(201)->assertJsonPath('data.version', 1);

    $run = $this->postJson("/api/v1/payments/{$s['payment']->id}/allocations", [], b92H($t))->assertStatus(201)->json('data');
    expect($run['rule_version'])->toBe(1)
        ->and(collect($run['lines'])->map(fn ($l) => [$l['target_category'], $l['amount_minor']])->all())->toBe([['TAX', 2000], ['PREMIUM', 20000], ['PREMIUM', 8000]])
        ->and($run['lines'][1]['financial_obligation_id'])->toBe($obl)
        ->and($calls->getArrayCopy())->toBe([[$obl, 20000]]);

    app(AllocationService::class)->reverse($t->id, $run['id'], 'ALLOCATION_ERROR', 'wrong policy', null);
    expect($calls->getArrayCopy())->toBe([[$obl, 20000], [$obl, -20000]]);

    // Old rule version is kept, superseded.
    app(AllocationRuleService::class)->publish($t->id, 'OLDEST_DUE_FIRST', [], 'back to default', null);
    expect(DB::table('allocation_rule_versions')->where('tenant_id', $t->id)->pluck('status', 'version')->all())->toBe([1 => 'SUPERSEDED', 2 => 'ACTIVE']);
});

it('REQ-PAY-004/005 enforces permissions, tenant isolation and payment status', function () {
    $s = b92Setup();
    $t = $s['tenant'];
    Passport::actingAs(b92Staff($t, ['premium_status.read']));
    $this->postJson("/api/v1/payments/{$s['payment']->id}/allocations", [], b92H($t))->assertForbidden();

    $other = b92Setup();
    Passport::actingAs(b92Staff($t));
    $this->getJson("/api/v1/policies/{$other['policy']->id}/premium-status", b92H($t))->assertNotFound();
    $this->postJson("/api/v1/payments/{$other['payment']->id}/allocations", [], b92H($t))->assertNotFound();

    DB::table('payment_intents')->where('id', $s['payment']->id)->update(['status' => 'PENDING_CUSTOMER']);
    app(PremiumComponentService::class)->captureFromTerms($s['policy'], null);
    $this->postJson("/api/v1/payments/{$s['payment']->id}/allocations", [], b92H($t))->assertStatus(422);
});
