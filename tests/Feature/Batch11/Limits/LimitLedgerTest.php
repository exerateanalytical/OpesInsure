<?php

declare(strict_types=1);

// Batch 11 C4 — REQ-CLM-004 limit / aggregate exhaustion engine.

use App\Application\Claims\Limits\LimitExhausted;
use App\Application\Claims\Limits\LimitLedger;
use App\Application\Payments\WebhookProcessingService;
use App\Application\Policies\PolicyIssuanceService;
use App\Models\Claim;
use App\Models\PolicyIssuanceRequest;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function c4User(): User
{
    return User::create(['full_name' => 'Lim '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

function c4Staff(Tenant $t, array $perms): User
{
    $u = c4User();
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => 'CLM', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $t->id, 'code' => 'CLM_'.Str::random(4), 'permissions' => $perms, 'is_system' => false])->id);

    return $u;
}

/** Issued policy (RC per-claim 1,000,000; DOM per-claim 800,000) + a policy-wide AGGREGATE of 1,500,000. */
function c4Policy(): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => [
        'offer_id' => null, 'quote_id' => $f['quote']->id, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF',
        'coverage_snapshot' => ['coverages' => [
            ['code' => 'RC', 'name' => ['en' => 'Liability'], 'mandatory' => true, 'optional' => false, 'limit_minor' => 1_000_000, 'deductible_minor' => null],
            ['code' => 'DOM', 'name' => ['en' => 'Own damage'], 'mandatory' => false, 'optional' => true, 'limit_minor' => 800_000, 'deductible_minor' => 10_000],
        ]],
    ]]);
    $f['payment'] = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'PENDING_CUSTOMER', 'requested_by' => $f['user']->id]);
    app(WebhookProcessingService::class)->process('fake', 'evt-'.Str::uuid(), [
        'payment_reference' => $f['payment']->provider_reference, 'amount_minor' => $f['payment']->amount_minor,
        'currency' => $f['payment']->currency, 'status' => 'SUCCEEDED',
    ], 'sig');
    $request = PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->firstOrFail();
    $f['policy'] = app(PolicyIssuanceService::class)->approve($request, ['carrier_reference' => 'CR-C4'], c4User());

    $version = DB::table('policy_versions')->where('policy_id', $f['policy']->id)->first();
    $f['version_id'] = $version->id;
    $cov = fn (string $code) => DB::table('policy_coverages')->where('policy_version_id', $version->id)->where('coverage_code', $code)->value('id');
    $f['rc'] = DB::table('policy_limits')->where('policy_coverage_id', $cov('RC'))->where('limit_type', 'PER_CLAIM')->value('id');
    $f['dom'] = DB::table('policy_limits')->where('policy_coverage_id', $cov('DOM'))->where('limit_type', 'PER_CLAIM')->value('id');
    $f['dom_deductible'] = DB::table('policy_limits')->where('policy_coverage_id', $cov('DOM'))->where('limit_type', 'DEDUCTIBLE')->value('id');
    $f['agg'] = (string) Str::uuid();
    DB::table('policy_limits')->insert(['id' => $f['agg'], 'policy_id' => $f['policy']->id, 'policy_version_id' => $version->id, 'policy_coverage_id' => null,
        'limit_type' => 'AGGREGATE', 'amount_minor' => 1_500_000, 'currency' => 'XAF', 'created_at' => now(), 'updated_at' => now()]);

    return $f;
}

function c4Claim(array $f): Claim
{
    return Claim::create(['tenant_id' => $f['tenant']->id, 'policy_id' => $f['policy']->id, 'claim_number' => 'CLM-C4-'.Str::random(6),
        'status' => 'SUBMITTED', 'loss_occurred_at' => now(), 'loss_details' => ['description' => 'x'], 'currency' => 'XAF']);
}

function c4Limit(string $id): object
{
    return DB::table('policy_limits')->where('id', $id)->first();
}

beforeEach(function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
});

it('REQ-CLM-004 reserve cascades to the aggregate and remaining = limit − consumed − reserved', function () {
    $f = c4Policy();
    $claim = c4Claim($f);
    $ledger = app(LimitLedger::class);

    $res = $ledger->reserve($claim, $f['rc'], 400_000, ['reference_type' => 'claim_reserve', 'reason' => 'initial']);
    expect($res['movements'])->toHaveCount(2)
        ->and(c4Limit($f['rc'])->reserved_minor)->toBe(400_000)
        ->and(c4Limit($f['agg'])->reserved_minor)->toBe(400_000);

    $r = $ledger->remaining($f['policy'], 'RC', now(), $claim);
    $byType = collect($r['limits'])->keyBy('limit_type');
    expect($r['policy_version_id'])->toBe($f['version_id'])
        ->and($byType['PER_CLAIM']['remaining_minor'])->toBe(600_000)
        ->and($byType['PER_CLAIM']['claim_remaining_minor'])->toBe(600_000)
        ->and($byType['AGGREGATE']['remaining_minor'])->toBe(1_100_000)
        ->and($r['remaining_minor'])->toBe(600_000)
        ->and($ledger->perClaimLimitId($claim, 'RC'))->toBe($f['rc']);
});

it('REQ-CLM-004 concurrency: two reservations jointly exceeding remaining — the second is refused and nothing goes negative', function () {
    $f = c4Policy();
    $a = c4Claim($f);
    $b = c4Claim($f);
    $ledger = app(LimitLedger::class);

    // Per-claim: each claim has 800,000 on DOM, but the aggregate only 1,500,000.
    $ledger->reserve($a, $f['dom'], 800_000);
    $ledger->reserve($b, $f['rc'], 600_000);
    expect(c4Limit($f['agg'])->reserved_minor)->toBe(1_400_000);

    $c = c4Claim($f);
    try {
        $ledger->reserve($c, $f['dom'], 200_000);
        $this->fail('second reservation should be refused');
    } catch (LimitExhausted $e) {
        expect($e->reasonCode)->toBe('limit_exhausted')->and($e->limitId)->toBe($f['agg'])->and($e->remainingMinor)->toBe(100_000);
    }
    // Refusal is atomic: neither the per-claim nor the aggregate counters moved.
    expect(c4Limit($f['agg'])->reserved_minor)->toBe(1_400_000)
        ->and(c4Limit($f['dom'])->reserved_minor)->toBe(800_000)
        ->and(DB::table('policy_limit_movements')->where('claim_id', $c->id)->count())->toBe(0);

    // Same claim, per-claim limit: 500k + 400k > 800k.
    $d = c4Claim($f);
    $ledger->release($a, $f['dom'], 700_000);
    $ledger->reserve($d, $f['dom'], 500_000);
    expect(fn () => $ledger->reserve($d, $f['dom'], 400_000))->toThrow(LimitExhausted::class);

    // DB backstop: an AGGREGATE row can never be over-committed, even bypassing the service.
    expect(fn () => DB::transaction(fn () => DB::table('policy_limits')->where('id', $f['agg'])->update(['reserved_minor' => 2_000_000])))->toThrow(\Illuminate\Database\QueryException::class);
});

it('REQ-CLM-004 consume draws down the reserve, reverse returns consumption, release cannot exceed the claim reserve', function () {
    $f = c4Policy();
    $claim = c4Claim($f);
    $ledger = app(LimitLedger::class);

    $ledger->reserve($claim, $f['rc'], 300_000);
    $pay = $ledger->consume($claim, $f['rc'], 500_000, ['reference_type' => 'claim_payment', 'reference_id' => (string) Str::uuid(), 'idempotency_key' => 'pay-1']);
    expect(c4Limit($f['rc']))->reserved_minor->toBe(0)->consumed_minor->toBe(500_000)
        ->and(c4Limit($f['agg']))->reserved_minor->toBe(0)->consumed_minor->toBe(500_000);

    // Idempotent replay returns the same group and moves nothing.
    $again = $ledger->consume($claim, $f['rc'], 500_000, ['idempotency_key' => 'pay-1']);
    expect($again['group_id'])->toBe($pay['group_id'])->and(c4Limit($f['rc'])->consumed_minor)->toBe(500_000);

    expect(fn () => $ledger->consume($claim, $f['rc'], 600_000))->toThrow(LimitExhausted::class);
    expect(fn () => $ledger->release($claim, $f['rc'], 1))->toThrow(LimitExhausted::class);

    $rev = $ledger->reverse($pay['group_id'], ['reason' => 'payment reversed']);
    expect($rev['movements'])->toHaveCount(2)
        ->and(c4Limit($f['rc'])->consumed_minor)->toBe(0)->and(c4Limit($f['agg'])->consumed_minor)->toBe(0);
    expect($ledger->reverse($pay['group_id'])['group_id'])->toBe($rev['group_id']);

    expect(fn () => $ledger->reserve($claim, $f['dom_deductible'], 1))->toThrow(LimitExhausted::class);
    $other = c4Policy();
    expect(fn () => $ledger->reserve($claim, $other['rc'], 1))->toThrow(LimitExhausted::class);

    // Ledger is append-only.
    expect(fn () => DB::transaction(fn () => DB::table('policy_limit_movements')->limit(1)->delete()))->toThrow(\Illuminate\Database\QueryException::class);
    expect(DB::table('policy_limit_movements')->where('claim_id', $claim->id)->pluck('movement_type')->unique()->sort()->values()->all())
        ->toBe(['CONSUME', 'RESERVE', 'REVERSE']);
});

it('REQ-CLM-004 read API per policy and per claim is tenant-scoped and permission-guarded', function () {
    $f = c4Policy();
    $claim = c4Claim($f);
    app(LimitLedger::class)->reserve($claim, $f['rc'], 250_000);
    $h = ['X-Tenant-Id' => $f['tenant']->id];

    Passport::actingAs(c4Staff($f['tenant'], ['policies.read']));
    $this->getJson("/api/v1/claims/{$claim->id}/limits", $h)->assertForbidden();

    Passport::actingAs(c4Staff($f['tenant'], ['claims.view']));
    $this->getJson("/api/v1/policies/{$f['policy']->id}/limits", $h)->assertOk()->assertJsonCount(3, 'data.limits');
    $this->getJson("/api/v1/policies/{$f['policy']->id}/limits?coverage=RC", $h)->assertOk()
        // Without a claim, the binding headroom is a fresh claim's: min(per-claim 1,000,000, aggregate 1,250,000).
        ->assertJsonPath('data.remaining_minor', 1_000_000)->assertJsonPath('data.limits.0.limit_type', 'AGGREGATE')
        ->assertJsonPath('data.limits.0.remaining_minor', 1_250_000)->assertJsonPath('data.limits.1.remaining_minor', 750_000);
    $this->getJson("/api/v1/claims/{$claim->id}/limits", $h)->assertOk()
        ->assertJsonCount(2, 'data.balances')->assertJsonCount(2, 'data.movements')
        ->assertJsonPath('data.balances.0.reserved_minor', 250_000);

    $other = Tenant::create(['type' => 'BROKER', 'legal_name' => 'Other', 'slug' => 'o-'.Str::lower(Str::random(6)), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    Passport::actingAs(c4Staff($other, ['claims.view']));
    $this->getJson("/api/v1/claims/{$claim->id}/limits", ['X-Tenant-Id' => $other->id])->assertNotFound();
    $this->getJson("/api/v1/policies/{$f['policy']->id}/limits", ['X-Tenant-Id' => $other->id])->assertNotFound();
});
