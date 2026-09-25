<?php

declare(strict_types=1);

use App\Application\FinancialDistribution\BordereauService;
use App\Models\Bordereau;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

/** REQ-DUP-008 / REQ-STL-002 — one bordereau service + resource; items generated per type. */
function b105Fixture(): array
{
    $fx = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $maker = makeAuthTestUser($fx['tenant'], ['bordereaux.prepare', 'bordereaux.view', 'broker.bordereaux.manage', 'broker.bordereaux.submit'], 'B105_MAKER');
    $checker = makeAuthTestUser($fx['tenant'], ['bordereaux.approve', 'bordereaux.submit', 'bordereaux.view', 'broker.bordereaux.submit', 'carrier.bordereaux.decide'], 'B105_CHECKER');
    $policy = makeMobileTestPolicy($fx['proposal'], $fx['tenant'], $fx['carrier']->id, $fx['party']->id, ['issued_at' => now()->subDays(3), 'premium_minor' => 50000, 'currency' => 'XAF']);

    return [$fx, $maker, $checker, $policy];
}

function b105Prepare(array $fx, $maker, string $type, array $extra = []): Bordereau
{
    return app(BordereauService::class)->prepare([
        'tenant_id' => $fx['tenant']->id, 'carrier_id' => $fx['carrier']->id, 'type' => $type, 'currency' => 'XAF',
        'period_start' => now()->subMonth()->toDateString(), 'period_end' => now()->toDateString(),
        'idempotency_key' => (string) Str::uuid(), ...$extra,
    ], $maker);
}

function b105Transaction(array $fx, $policy, $user, string $type, array $attrs = []): string
{
    $id = (string) Str::uuid();
    DB::table('policy_transactions')->insert([
        'id' => $id, 'tenant_id' => $fx['tenant']->id, 'policy_id' => $policy->id, 'type' => $type, 'status' => 'APPROVED',
        'transaction_number' => 'PT-'.Str::random(10), 'effective_at' => now(), 'reason_code' => 'TEST', 'requested_by' => $user->id,
        'premium_delta_minor' => 0, 'currency' => 'XAF', 'approved_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(), ...$attrs,
    ]);

    return $id;
}

it('generates PREMIUM items from policies issued in the period', function () {
    [$fx, $maker, , $policy] = b105Fixture();
    DB::table('commission_accruals')->insert(['id' => (string) Str::uuid(), 'policy_id' => $policy->id, 'rule_version' => 'v1', 'amount_minor' => 5000, 'currency' => 'XAF', 'status' => 'ACCRUED', 'created_at' => now(), 'updated_at' => now()]);

    $b = b105Prepare($fx, $maker, 'PREMIUM');

    expect($b->item_count)->toBe(1)->and($b->gross_premium_minor)->toBe(50000)->and($b->commission_minor)->toBe(5000)->and($b->total_amount_minor)->toBe(50000);
    expect($b->items->first()->only(['transaction_type', 'source_type', 'source_id']))->toBe(['transaction_type' => 'NEW_BUSINESS', 'source_type' => 'POLICY', 'source_id' => $policy->id]);
});

it('generates ENDORSEMENT items from approved endorsements, several per policy', function () {
    [$fx, $maker, $checker, $policy] = b105Fixture();
    $e1 = b105Transaction($fx, $policy, $maker, 'ENDORSEMENT', ['premium_delta_minor' => 7000]);
    $e2 = b105Transaction($fx, $policy, $maker, 'ENDORSEMENT', ['premium_delta_minor' => -2000]);
    b105Transaction($fx, $policy, $maker, 'ENDORSEMENT', ['status' => 'PENDING_APPROVAL', 'approved_at' => null, 'premium_delta_minor' => 99]);
    b105Transaction($fx, $policy, $maker, 'ENDORSEMENT', ['approved_at' => now()->subYear(), 'premium_delta_minor' => 99]);

    $b = b105Prepare($fx, $maker, 'ENDORSEMENT');

    expect($b->item_count)->toBe(2)->and($b->total_amount_minor)->toBe(5000)->and($b->gross_premium_minor)->toBe(5000);
    expect($b->items->pluck('source_id')->sort()->values()->all())->toBe(collect([$e1, $e2])->sort()->values()->all());
    expect($b->items->pluck('transaction_type')->unique()->all())->toBe(['ENDORSEMENT']);
});

it('generates CANCELLATION items from approved cancellations (refund as a negative amount)', function () {
    [$fx, $maker, $checker, $policy] = b105Fixture();
    $tx = b105Transaction($fx, $policy, $maker, 'CANCELLATION');
    $cid = (string) Str::uuid();
    DB::table('policy_cancellations')->insert(['id' => $cid, 'tenant_id' => $fx['tenant']->id, 'policy_id' => $policy->id, 'policy_transaction_id' => $tx, 'status' => 'APPROVED', 'initiated_by' => 'INSURED', 'reason_code' => 'TEST', 'effective_at' => now(), 'refund_basis' => 'PRO_RATA', 'refund_minor' => 12000, 'currency' => 'XAF', 'requested_by' => $maker->id, 'decided_by' => $checker->id, 'decided_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);

    $b = b105Prepare($fx, $maker, 'CANCELLATION');

    expect($b->item_count)->toBe(1)->and($b->total_amount_minor)->toBe(-12000);
    expect($b->items->first()->only(['transaction_type', 'source_type', 'source_id']))->toBe(['transaction_type' => 'CANCELLATION', 'source_type' => 'POLICY_CANCELLATION', 'source_id' => $cid]);
});

it('generates CLAIM items from claims submitted in the period (normalising CLAIMS)', function () {
    [$fx, $maker, , $policy] = b105Fixture();
    $claim = (string) Str::uuid();
    DB::table('claims')->insert(['id' => $claim, 'tenant_id' => $fx['tenant']->id, 'policy_id' => $policy->id, 'claim_number' => 'CLM-'.Str::random(8), 'status' => 'APPROVED', 'loss_occurred_at' => now()->subDays(5), 'loss_details' => '{}', 'submitted_at' => now()->subDays(4), 'approved_amount_minor' => 30000, 'currency' => 'XAF', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('claims')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $fx['tenant']->id, 'policy_id' => $policy->id, 'claim_number' => 'CLM-'.Str::random(8), 'status' => 'DRAFT', 'loss_occurred_at' => now(), 'loss_details' => '{}', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $b = b105Prepare($fx, $maker, 'CLAIM');

    expect($b->item_count)->toBe(1)->and($b->total_amount_minor)->toBe(30000)->and($b->gross_premium_minor)->toBe(0);
    expect($b->items->first()->only(['transaction_type', 'source_id']))->toBe(['transaction_type' => 'CLAIM', 'source_id' => $claim]);
});

it('generates COMMISSION items from commission accruals raised in the period', function () {
    [$fx, $maker, , $policy] = b105Fixture();
    $a = (string) Str::uuid();
    DB::table('commission_accruals')->insert(['id' => $a, 'policy_id' => $policy->id, 'rule_version' => 'v1', 'amount_minor' => 4500, 'currency' => 'XAF', 'status' => 'ACCRUED', 'created_at' => now()->subDay(), 'updated_at' => now()]);
    DB::table('commission_accruals')->insert(['id' => (string) Str::uuid(), 'policy_id' => $policy->id, 'rule_version' => 'v1', 'amount_minor' => 1, 'currency' => 'XAF', 'status' => 'ACCRUED', 'created_at' => now()->subYear(), 'updated_at' => now()]);

    $b = b105Prepare($fx, $maker, 'COMMISSION');

    expect($b->item_count)->toBe(1)->and($b->commission_minor)->toBe(4500)->and($b->total_amount_minor)->toBe(4500);
    expect($b->items->first()->only(['transaction_type', 'source_type', 'source_id']))->toBe(['transaction_type' => 'COMMISSION', 'source_type' => 'COMMISSION_ACCRUAL', 'source_id' => $a]);
});

it('routes the broker alias through the one service (governed fields, deprecation headers)', function () {
    [$fx, $maker, $checker, $policy] = b105Fixture();
    Passport::actingAs($maker);

    $r = $this->postJson('/api/v1/broker/bordereaux', [
        'carrier_id' => $fx['carrier']->id, 'type' => 'claims', 'period_start' => now()->subMonth()->toDateString(), 'period_end' => now()->toDateString(), 'policy_ids' => [$policy->id],
    ], tenantHeaderFor($fx['tenant']))->assertCreated()->assertHeader('Deprecation', 'true');

    $b = Bordereau::findOrFail($r->json('data.id'));
    expect($b->type)->toBe('CLAIM')->and($b->content_hash)->not->toBeEmpty()->and($b->idempotency_key)->not->toBeEmpty();
    $this->assertDatabaseHas('outbox_messages', ['event_name' => 'bordereau.prepared', 'aggregate_id' => $b->id]);

    // Maker-checker, then the broker submit alias (preparer refused, checker allowed).
    app(BordereauService::class)->approve($b, $checker);
    $this->postJson("/api/v1/broker/bordereaux/{$b->id}/submit", ['notes' => 'Monthly claims bordereau for the carrier.'], tenantHeaderFor($fx['tenant']))->assertForbidden();
    Passport::actingAs($checker);
    $this->postJson("/api/v1/broker/bordereaux/{$b->id}/submit", ['notes' => 'Monthly claims bordereau for the carrier.'], tenantHeaderFor($fx['tenant']))->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
    $this->assertDatabaseHas('financial_distribution_events', ['aggregate_type' => 'BORDEREAU', 'aggregate_id' => $b->id, 'to_status' => 'SUBMITTED']);

    // Carrier decision alias: REJECTED goes through the service's reject().
    $this->postJson("/api/v1/carrier/bordereaux/{$b->id}/decision", ['carrier_reference' => 'CR-9', 'decision' => 'REJECTED', 'notes' => 'Two claims lack a loss adjuster report.'], tenantHeaderFor($fx['tenant']))
        ->assertOk()->assertJsonPath('data.status', 'REJECTED')->assertHeader('Deprecation', 'true');
    expect($b->refresh()->rejection_reason)->toBe('Two claims lack a loss adjuster report.')->and($b->carrier_reference)->toBe('CR-9');
    $this->assertDatabaseHas('outbox_messages', ['event_name' => 'bordereau.rejected', 'aggregate_id' => $b->id]);
});

it('refuses submission by the preparer in the service itself', function () {
    [$fx, $maker, $checker] = b105Fixture();
    $b = b105Prepare($fx, $maker, 'PREMIUM');
    app(BordereauService::class)->approve($b, $checker);

    expect(fn () => app(BordereauService::class)->submit($b, $maker))->toThrow(ValidationException::class);
});

it('lists and shows bordereaux on the canonical resource, tenant-scoped', function () {
    [$fx, $maker] = b105Fixture();
    $mine = b105Prepare($fx, $maker, 'PREMIUM');
    [$other, $otherMaker] = b105Fixture();
    $theirs = b105Prepare($other, $otherMaker, 'PREMIUM');

    Passport::actingAs($maker);
    $list = $this->getJson('/api/v1/bordereaux?type=premium', tenantHeaderFor($fx['tenant']))->assertOk();
    expect(collect($list->json('data'))->pluck('id')->all())->toBe([$mine->id]);
    $this->getJson("/api/v1/bordereaux/{$mine->id}", tenantHeaderFor($fx['tenant']))->assertOk()->assertJsonCount(1, 'items');
    $this->getJson("/api/v1/bordereaux/{$theirs->id}", tenantHeaderFor($fx['tenant']))->assertNotFound();
});
