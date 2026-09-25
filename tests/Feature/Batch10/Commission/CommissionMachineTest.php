<?php

declare(strict_types=1);

// Batch 10-1 — REQ-COM-001 commission machine (WF-064..067).

use App\Application\Commissions\Machine\CommissionLifecycleService;
use App\Application\Commissions\Machine\CommissionMachine;
use App\Application\FinancialDistribution\CommissionService;
use App\Application\Payments\WebhookProcessingService;
use App\Application\Policies\PolicyIssuanceService;
use App\Domain\Shared\StateMachine\StateMachineRegistry;
use App\Models\CommissionAccrual;
use App\Models\Party;
use App\Models\Policy;
use App\Models\PolicyIssuanceRequest;
use App\Models\PolicyTransaction;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function c101User(): User
{
    return User::create(['full_name' => 'Com '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

function c101Staff(Tenant $t, array $perms): User
{
    $u = c101User();
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => 'FIN', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $t->id, 'code' => 'FIN_'.Str::random(4), 'permissions' => $perms, 'is_system' => false])->id);

    return $u;
}

/** Issues a policy through the real issuance path with an attributed partner and an approved 10% rule. */
function c101Issue(int $vestingDays = 0, bool $withPartner = true, bool $postingProfiles = true): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $maker = c101User();
    $checker = c101User();
    $partnerId = null;
    if ($withPartner) {
        $partnerParty = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Agent Co', 'status' => 'ACTIVE']);
        $partnerId = (string) Str::uuid();
        DB::table('partners')->insert(['id' => $partnerId, 'tenant_id' => $f['tenant']->id, 'party_id' => $partnerParty->id, 'type' => 'AGENT', 'status' => 'ACTIVE', 'compliance' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_attributions')->insert(['id' => (string) Str::uuid(), 'party_id' => $f['party']->id, 'partner_id' => $partnerId, 'origin_type' => 'AGENT',
            'terms_version' => 'v1', 'effective_from' => now()->subDay(), 'status' => 'ACTIVE', 'recorded_by' => $maker->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('commission_rule_versions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $f['tenant']->id, 'carrier_id' => $f['carrier']->id, 'product_id' => $f['product']->id,
        'partner_id' => null, 'version' => 1, 'effective_from' => now()->subMonth()->toDateString(), 'status' => 'APPROVED', 'basis_points' => 1000, 'vesting_days' => $vestingDays,
        'holdback_basis_points' => 0, 'conditions' => '{}', 'rule_hash' => str_repeat('a', 64), 'created_by' => $maker->id, 'approved_by' => $checker->id, 'approved_at' => now(),
        'created_at' => now(), 'updated_at' => now()]);
    if ($postingProfiles) {
        $acc = fn (string $code, string $type) => tap((string) Str::uuid(), fn ($id) => DB::table('ledger_accounts')->insert(['id' => $id, 'tenant_id' => $f['tenant']->id, 'code' => $code, 'name' => $code, 'type' => $type, 'currency' => 'XAF', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]));
        $expense = $acc('6220', 'EXPENSE');
        $payable = $acc('4010', 'LIABILITY');
        foreach (['commission.accrued' => [$expense, $payable], 'commission.earned' => [$payable, $payable], 'commission.clawed_back' => [$payable, $expense]] as $event => [$dr, $cr]) {
            DB::table('financial_posting_profiles')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $f['tenant']->id, 'event_type' => $event, 'currency' => 'XAF',
                'debit_account_id' => $dr, 'credit_account_id' => $cr, 'status' => 'APPROVED', 'created_by' => $maker->id, 'approved_by' => $checker->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => [
        'offer_id' => null, 'quote_id' => $f['quote']->id, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF',
        'coverage_snapshot' => ['coverages' => [['code' => 'RC', 'name' => ['en' => 'Liability'], 'mandatory' => true, 'optional' => false, 'limit_minor' => 50_000_000, 'deductible_minor' => null]]],
    ]]);
    $payment = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'PENDING_CUSTOMER', 'requested_by' => $f['user']->id, 'amount_minor' => 100000]);
    app(WebhookProcessingService::class)->process('fake', 'evt-'.Str::uuid(), [
        'payment_reference' => $payment->provider_reference, 'amount_minor' => 100000, 'currency' => 'XAF', 'status' => 'SUCCEEDED',
    ], 'sig');
    $request = PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->firstOrFail();
    $policy = app(PolicyIssuanceService::class)->approve($request, ['carrier_reference' => 'CR-101'], c101User());

    return ['policy' => $policy, 'tenant' => $f['tenant'], 'partner_id' => $partnerId, 'accrual' => CommissionAccrual::where('policy_id', $policy->id)->first()];
}

function c101Cancellation(Policy $p, string $effectiveAt): PolicyTransaction
{
    return new PolicyTransaction(['id' => (string) Str::uuid(), 'type' => 'CANCELLATION', 'effective_at' => $effectiveAt, 'premium_delta_minor' => 0]);
}

beforeEach(function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
});

it('REQ-COM-001 registers the commission machine with a blueprint mapping for every stored status', function () {
    expect(app(StateMachineRegistry::class)->get('commission')->name)->toBe('commission');
    foreach (array_keys(CommissionMachine::definition()->states()) as $code) {
        expect(CommissionMachine::BLUEPRINT)->toHaveKey($code);
    }
    expect(array_values(array_unique(CommissionMachine::BLUEPRINT)))->toEqualCanonicalizing(
        ['SALE', 'CALCULATED', 'ACCRUED', 'EARNED', 'APPROVED', 'PAYABLE', 'PAID', 'REVERSED', 'CLAWED_BACK', 'ADJUSTED', 'DISPUTED']);
    expect(CommissionMachine::blueprintState('PENDING'))->toBe('ACCRUED')->and(CommissionMachine::blueprintState('VESTED'))->toBe('PAYABLE');
});

it('REQ-COM-001 policy issuance accrues commission and a settled premium earns it, with journals', function () {
    $x = c101Issue();
    $a = $x['accrual'];
    expect($a)->not->toBeNull()
        ->and($a->partner_id)->toBe($x['partner_id'])
        ->and((int) $a->amount_minor)->toBe(10000)
        ->and($a->status)->toBe('EARNED')
        ->and($a->earned_at)->not->toBeNull();
    expect(DB::table('workflow_transition_history')->where('subject_id', $a->id)->orderBy('occurred_at')->pluck('event')->all())->toBe(['accrue', 'earn']);
    expect(DB::table('outbox_messages')->where('aggregate_id', $a->id)->where('event_name', 'commission.earned')->exists())->toBeTrue();
    expect(DB::table('journals')->where('reference_type', 'commission.accrued')->where('reference_id', $a->id)->exists())->toBeTrue();
    expect(DB::table('journals')->where('reference_type', 'commission.earned')->where('reference_id', $a->id)->exists())->toBeTrue();
});

it('REQ-COM-001 issuance without an attributed partner accrues nothing, and missing posting profiles do not block accrual', function () {
    expect(c101Issue(withPartner: false)['accrual'])->toBeNull();
    $x = c101Issue(postingProfiles: false);
    expect($x['accrual']->status)->toBe('EARNED')
        ->and(DB::table('journals')->where('reference_id', $x['accrual']->id)->exists())->toBeFalse();
});

it('REQ-COM-001 approved commission becomes payable after vesting as a PAYABLE COMMISSION obligation', function () {
    $x = c101Issue(vestingDays: 7);
    $svc = app(CommissionLifecycleService::class);
    $a = $svc->approve($x['accrual'], c101User(), 'ok');
    expect($a->status)->toBe('APPROVED')->and($a->approved_by)->not->toBeNull();
    expect(fn () => $svc->makePayable($a))->toThrow(ValidationException::class);

    $this->travel(8)->days();
    expect($svc->advance()['payable'])->toBe(1);
    $a->refresh();
    expect($a->status)->toBe('VESTED')->and(CommissionMachine::blueprintState($a->status))->toBe('PAYABLE')->and((int) $a->vested_minor)->toBe(10000);
    $o = DB::table('financial_obligations')->where('id', $a->financial_obligation_id)->first();
    expect($o->kind)->toBe('PAYABLE')->and($o->type)->toBe('COMMISSION')->and($o->creditor_type)->toBe('partner')
        ->and($o->creditor_id)->toBe($x['partner_id'])->and((int) $o->amount_minor)->toBe(10000);

    // A payout pays it (PayoutService raises paid_minor), then the machine settles the obligation and moves to PAID.
    $a->update(['paid_minor' => 10000]);
    $a = $svc->settlePaid($a, 'payout:test');
    expect($a->status)->toBe('PAID')->and(DB::table('financial_obligations')->where('id', $o->id)->value('status'))->toBe('SETTLED');
});

it('REQ-COM-001 cancellation claws back commission pro-rata and re-sizes the payable', function () {
    $x = c101Issue();
    $p = $x['policy']->refresh();
    $svc = app(CommissionLifecycleService::class);
    $a = $svc->makePayable($svc->approve($x['accrual'], c101User()));
    $mid = $p->coverage_starts_at->copy()->addSeconds((int) ($p->coverage_starts_at->diffInSeconds($p->coverage_ends_at) / 2));
    $svc->onPolicyCancelled($p, c101Cancellation($p, $mid->toIso8601String()));
    $a->refresh();
    expect((int) $a->clawed_back_minor)->toBeGreaterThanOrEqual(4900)->toBeLessThanOrEqual(5100);
    $open = DB::table('financial_obligations')->where('source_type', 'commission_accrual')->where('source_id', $a->id)->where('status', 'OPEN')->get();
    expect($open)->toHaveCount(1)->and((int) $open[0]->amount_minor)->toBe(10000 - (int) $a->clawed_back_minor);
    expect(DB::table('journals')->where('reference_type', 'commission.clawed_back')->exists())->toBeTrue();
    expect(DB::table('outbox_messages')->where('aggregate_id', $a->id)->where('event_name', 'commission.clawed_back')->exists())->toBeTrue();
});

it('REQ-COM-001 the same cancellation claws back once, and a cancellation from inception reverses unearned commission', function () {
    $x = c101Issue();
    $p = $x['policy']->refresh();
    $svc = app(CommissionLifecycleService::class);
    $t = c101Cancellation($p, $p->coverage_starts_at->copy()->addDays(73)->toIso8601String());
    $svc->onPolicyCancelled($p, $t);
    $once = (int) $x['accrual']->refresh()->clawed_back_minor;
    $svc->onPolicyCancelled($p, $t);
    expect($once)->toBeGreaterThan(0)->and((int) $x['accrual']->refresh()->clawed_back_minor)->toBe($once);

    $a = CommissionAccrual::create(['tenant_id' => $p->tenant_id, 'policy_id' => $p->id, 'partner_id' => $x['partner_id'], 'rule_version' => '1', 'amount_minor' => 5000,
        'vested_minor' => 0, 'paid_minor' => 0, 'clawed_back_minor' => 0, 'currency' => 'XAF', 'status' => 'PENDING', 'source_type' => 'POLICY', 'source_id' => $p->id, 'idempotency_key' => 'x-'.Str::random(6)]);
    $svc->onPolicyCancelled($p, c101Cancellation($p, $p->coverage_starts_at->toIso8601String()));
    expect($a->refresh()->status)->toBe('REVERSED')->and((int) $a->clawed_back_minor)->toBe(5000);
});

it('REQ-COM-001 clawback beyond what is unpaid becomes a partner recovery receivable; full clawback ends CLAWED_BACK', function () {
    $x = c101Issue();
    $p = $x['policy']->refresh();
    $svc = app(CommissionLifecycleService::class);
    $a = $svc->makePayable($svc->approve($x['accrual'], c101User()));
    $a->update(['paid_minor' => 8000]);
    $svc->onPolicyCancelled($p, c101Cancellation($p, $p->coverage_starts_at->toIso8601String()));
    $a->refresh();
    expect((int) $a->clawed_back_minor)->toBe(2000);
    $r = DB::table('financial_obligations')->where('source_id', $a->id)->where('kind', 'RECEIVABLE')->first();
    expect($r->type)->toBe('COMMISSION')->and($r->debtor_type)->toBe('partner')->and((int) $r->amount_minor)->toBe(8000);

    $b = CommissionAccrual::create(['tenant_id' => $p->tenant_id, 'policy_id' => $p->id, 'partner_id' => $x['partner_id'], 'rule_version' => '1', 'amount_minor' => 3000,
        'vested_minor' => 0, 'paid_minor' => 0, 'clawed_back_minor' => 0, 'currency' => 'XAF', 'status' => 'EARNED', 'source_type' => 'POLICY', 'source_id' => $p->id, 'idempotency_key' => 'y-'.Str::random(6)]);
    app(CommissionService::class)->clawback($b, 3000, 'CORRECTION', c101User());
    expect($b->refresh()->status)->toBe('CLAWED_BACK');
});

it('REQ-COM-001 adjustment needs re-approval by another user; disputes resolve through ADJUSTED', function () {
    $x = c101Issue();
    $svc = app(CommissionLifecycleService::class);
    $maker = c101User();
    $a = $svc->adjust($x['accrual'], 12000, 'Rate correction', $maker);
    expect($a->status)->toBe('ADJUSTED')->and((int) $a->amount_minor)->toBe(12000);
    expect(DB::table('commission_movements')->where('commission_accrual_id', $a->id)->where('type', 'ADJUSTMENT')->value('amount_minor'))->toBe(2000);
    expect(fn () => $svc->approve($a, $maker))->toThrow(ValidationException::class);
    $a = $svc->approve($a, c101User());
    expect($a->status)->toBe('APPROVED');

    $a = $svc->dispute($a, 'Partner claims 15%', $maker);
    expect($a->status)->toBe('DISPUTED');
    $checker = c101User();
    $a = $svc->resolveDispute($a, 11000, 'Settled at 11%', $checker);
    expect($a->status)->toBe('ADJUSTED')->and((int) $a->amount_minor)->toBe(11000);
    expect(fn () => $svc->earn($a))->toThrow(ValidationException::class); // not a transition from ADJUSTED
});

it('REQ-COM-001 endorsement with additional premium accrues an ENDORSEMENT commission; return premium claws back', function () {
    $x = c101Issue();
    $p = $x['policy']->refresh();
    $svc = app(CommissionLifecycleService::class);
    $up = new PolicyTransaction(['type' => 'ENDORSEMENT', 'premium_delta_minor' => 20000]);
    $up->id = (string) Str::uuid();
    $svc->onEndorsementApproved($p, $up);
    $e = CommissionAccrual::where('policy_id', $p->id)->where('source_type', 'ENDORSEMENT')->firstOrFail();
    expect((int) $e->amount_minor)->toBe(2000)->and($e->source_id)->toBe($up->id);

    $p->update(['premium_minor' => 50000]);
    $down = new PolicyTransaction(['type' => 'ENDORSEMENT', 'premium_delta_minor' => -50000]);
    $down->id = (string) Str::uuid();
    $svc->onEndorsementApproved($p, $down);
    expect((int) $x['accrual']->refresh()->clawed_back_minor)->toBe(5000);
});

it('REQ-COM-001 lifecycle endpoints are tenant-scoped and permission-guarded', function () {
    $x = c101Issue();
    $a = $x['accrual'];
    Passport::actingAs(c101Staff($x['tenant'], ['commission.read']));
    $this->getJson("/api/v1/commissions/accruals/{$a->id}", ['X-Tenant-Id' => $x['tenant']->id])
        ->assertOk()->assertJsonPath('data.blueprint_state', 'EARNED')->assertJsonCount(2, 'data.history');
    $this->postJson("/api/v1/commissions/accruals/{$a->id}/approve", [], ['X-Tenant-Id' => $x['tenant']->id])->assertForbidden();

    Passport::actingAs(c101Staff($x['tenant'], ['commission.approve']));
    $this->postJson("/api/v1/commissions/accruals/{$a->id}/approve", ['note' => 'ok'], ['X-Tenant-Id' => $x['tenant']->id])
        ->assertOk()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.blueprint_state', 'APPROVED');

    $other = Tenant::create(['type' => 'BROKER', 'legal_name' => 'Other', 'slug' => 'o-'.Str::lower(Str::random(6)), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    Passport::actingAs(c101Staff($other, ['commission.read']));
    $this->getJson("/api/v1/commissions/accruals/{$a->id}", ['X-Tenant-Id' => $other->id])->assertNotFound();
});
