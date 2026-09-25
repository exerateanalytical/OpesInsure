<?php

declare(strict_types=1);

use App\Application\Finance\Clearing\ClearingBatch;
use App\Application\Finance\Refunds\RefundObligationLink;
use App\Application\Policies\IssuanceQueue\IssuanceException;
use App\Application\Policies\PaymentIssuanceTrigger;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

function b96Paid(array $payment = []): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => ['offer_id' => null, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'EUR']]);
    $f['payment'] = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'SUCCEEDED', 'reconciled_at' => now(), 'requested_by' => $f['user']->id] + $payment);

    return $f;
}

beforeEach(function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
});

it('turns an issuance exception resolved as REFUND_REQUESTED into a refund candidate and drives it through WF-063 with maker-checker', function () {
    $f = b96Paid();
    app(PaymentIssuanceTrigger::class)->afterPaymentSucceeded($f['payment']); // EUR terms vs XAF payment → exception
    $ex = IssuanceException::where('proposal_id', $f['proposal']->id)->sole();
    $h = tenantHeaderFor($f['tenant']);

    $ops = makeAuthTestUser($f['tenant'], ['policies.issuance_queue.resolve']);
    Passport::actingAs($ops);
    $this->postJson("/api/v1/issuance-exceptions/{$ex->id}/resolve", ['resolution' => 'REFUND_REQUESTED', 'notes' => 'Carrier declined'], $h)->assertOk();

    $refund = Refund::where('source_type', 'issuance_exception')->where('source_id', $ex->id)->sole();
    expect($refund->status)->toBe('CANDIDATE')->and($refund->amount_minor)->toBe(100000)->and($refund->payment_intent_id)->toBe($f['payment']->id);
    expect(DB::table('issuance_exception_events')->where('issuance_exception_id', $ex->id)->where('action', 'RESOLVED')->value('metadata'))->toContain($refund->id);

    $maker = makeAuthTestUser($f['tenant'], ['refund.view', 'refund.request', 'refund.review']);
    $reviewer = makeAuthTestUser($f['tenant'], ['refund.review']);
    $checker = makeAuthTestUser($f['tenant'], ['refund.approve']);
    $payer = makeAuthTestUser($f['tenant'], ['refund.pay', 'refund.reconcile']);
    $reconciler = makeAuthTestUser($f['tenant'], ['refund.reconcile']);

    Passport::actingAs($maker);
    $this->getJson('/api/v1/refunds', $h)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $refund->id);
    $this->postJson("/api/v1/refunds/{$refund->id}/calculate", ['deductions' => [['code' => 'POLICY_FEE', 'amount_minor' => 5000]]], $h)
        ->assertOk()->assertJsonPath('data.status', 'CALCULATED')->assertJsonPath('data.amount_minor', 95000)->assertJsonPath('data.calculation.gross_minor', 100000);
    $this->postJson("/api/v1/refunds/{$refund->id}/calculate", ['gross_minor' => 100001], $h)->assertStatus(422);
    $this->postJson("/api/v1/refunds/{$refund->id}/review", [], $h)->assertStatus(422); // maker cannot review own calculation

    Passport::actingAs($reviewer);
    $this->postJson("/api/v1/refunds/{$refund->id}/review", [], $h)->assertOk()->assertJsonPath('data.status', 'REQUESTED');

    Passport::actingAs($checker);
    $this->postJson("/api/v1/refunds/{$refund->id}/pay", ['payout_method' => 'MOBILE_MONEY', 'provider_reference' => 'X'], $h)->assertForbidden();
    $this->postJson("/api/v1/refunds/{$refund->id}/approve", [], $h)->assertOk()->assertJsonPath('data.status', 'APPROVED');

    Passport::actingAs($payer);
    $this->postJson("/api/v1/refunds/{$refund->id}/pay", ['payout_method' => 'MOBILE_MONEY', 'provider_reference' => 'MOMO-RFD-1'], $h)->assertOk()->assertJsonPath('data.status', 'PAID');
    $this->postJson("/api/v1/refunds/{$refund->id}/reconcile", ['bank_reference' => 'BNK-1'], $h)->assertStatus(422); // payer ≠ reconciler

    Passport::actingAs($reconciler);
    $this->postJson("/api/v1/refunds/{$refund->id}/reconcile", ['bank_reference' => 'BNK-1'], $h)->assertOk()->assertJsonPath('data.status', 'RECONCILED');

    expect(DB::table('financial_case_events')->where('case_id', $refund->id)->orderBy('occurred_at')->pluck('to_status')->all())
        ->toBe(['CANDIDATE', 'CALCULATED', 'REQUESTED', 'APPROVED', 'PAID', 'RECONCILED']);
    expect(DB::table('outbox_messages')->where('aggregate_id', $refund->id)->pluck('event_name')->all())
        ->toContain('refund.candidate_created', 'refund.calculated', 'refund.reviewed', 'refund.approved', 'refund.paid', 'refund.reconciled');
});

it('blocks the calculator from approving, keeps the candidate idempotent, and tenant-scopes the queue', function () {
    $f = b96Paid();
    $h = tenantHeaderFor($f['tenant']);
    $maker = makeAuthTestUser($f['tenant'], ['refund.request', 'refund.approve', 'refund.view']);
    $other = makeAuthTestUser($f['tenant'], ['refund.review', 'refund.approve']);

    Passport::actingAs($maker);
    $id = $this->postJson("/api/v1/payments/{$f['payment']->id}/refund-candidates", ['reason_code' => 'DUPLICATE_PAYMENT', 'amount_minor' => 40000, 'source_type' => 'manual', 'source_id' => $f['payment']->id], $h)
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/payments/{$f['payment']->id}/refund-candidates", ['reason_code' => 'DUPLICATE_PAYMENT', 'source_type' => 'manual', 'source_id' => $f['payment']->id], $h)
        ->assertOk()->assertJsonPath('data.id', $id);
    // Balance: 100000 paid, 40000 held by the candidate → a legacy request for 70000 is refused.
    $this->postJson("/api/v1/payments/{$f['payment']->id}/refunds", ['amount_minor' => 70000, 'reason_code' => 'X', 'idempotency_key' => str_repeat('k', 20)], $h)->assertStatus(422);

    $this->postJson("/api/v1/refunds/{$id}/calculate", [], $h)->assertOk()->assertJsonPath('data.amount_minor', 100000);
    Passport::actingAs($other);
    $this->postJson("/api/v1/refunds/{$id}/review", [], $h)->assertOk();
    // Maker (requester + calculator) cannot approve their own refund.
    Passport::actingAs($maker);
    $this->postJson("/api/v1/refunds/{$id}/approve", [], $h)->assertStatus(422);
    Passport::actingAs($other);
    $this->postJson("/api/v1/refunds/{$id}/reject", ['reason' => 'Not a duplicate'], $h)->assertOk()->assertJsonPath('data.status', 'REJECTED');

    $foreign = makeAuthTestTenant('b96-other');
    Passport::actingAs(makeAuthTestUser($foreign, ['refund.view']));
    $this->getJson('/api/v1/refunds?status=REJECTED', tenantHeaderFor($foreign))->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson("/api/v1/refunds/{$id}", tenantHeaderFor($foreign))->assertNotFound();
});

it('links the refund to a payable obligation when a link is bound', function () {
    $f = b96Paid();
    $link = new class implements RefundObligationLink
    {
        public array $settled = [];

        public function open(Refund $refund): ?string
        {
            return (string) \Illuminate\Support\Str::uuid();
        }

        public function settle(Refund $refund): void
        {
            $this->settled[] = $refund->id;
        }
    };
    app()->instance(RefundObligationLink::class, $link);
    $engine = app(\App\Application\Finance\Refunds\RefundEngine::class);
    $a = makeAuthTestUser($f['tenant'], []);
    $b = makeAuthTestUser($f['tenant'], []);
    $r = $engine->candidate($f['payment'], 'manual', null, 'GOODWILL', $a, 1000);
    $r = $engine->review($engine->calculate($r, [], $a), $b);
    $r = $engine->approve($r, $b);
    expect($r->financial_obligation_id)->not->toBeNull();
    $engine->pay($r, ['payout_method' => 'BANK_TRANSFER', 'provider_reference' => 'T1'], $b);
    expect($link->settled)->toBe([$r->id]);
});

it('tracks mobile-money clearing: provider success stays in suspense until bank settlement is reconciled', function () {
    $f = b96Paid(['provider' => 'mtn_momo']);
    $unallocated = makeMobileTestPayment($f['proposal'], $f['tenant'], ['provider' => 'mtn_momo', 'amount_minor' => 25000, 'reconciled_at' => null]);
    makeMobileTestPayment($f['proposal'], $f['tenant'], ['provider' => 'mtn_momo', 'status' => 'FAILED', 'amount_minor' => 999]);
    $h = tenantHeaderFor($f['tenant']);
    $ops = makeAuthTestUser($f['tenant'], ['clearing.view', 'clearing.manage', 'clearing.reconcile']);
    $checker = makeAuthTestUser($f['tenant'], ['clearing.view', 'clearing.reconcile']);

    Passport::actingAs(makeAuthTestUser($f['tenant'], ['refund.view']));
    $this->getJson('/api/v1/clearing/suspense', $h)->assertForbidden();

    Passport::actingAs($ops);
    $this->getJson('/api/v1/clearing/suspense', $h)->assertOk()
        ->assertJsonPath('data.0.provider', 'mtn_momo')->assertJsonPath('data.0.unallocated_minor', 25000)
        ->assertJsonPath('data.0.unsettled_minor', 100000)->assertJsonPath('data.0.suspense_minor', 125000);

    $batchId = $this->postJson('/api/v1/clearing/batches', ['provider' => 'mtn_momo', 'settlement_reference' => 'MTN-2026-10-14', 'settlement_date' => '2026-10-14', 'currency' => 'XAF'], $h)
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/clearing/batches/{$batchId}/items", ['payment_ids' => [$f['payment']->id, $unallocated->id]], $h)->assertOk()->assertJsonPath('data.expected_minor', 125000);
    // Another batch cannot claim an already-cleared payment.
    $other = $this->postJson('/api/v1/clearing/batches', ['provider' => 'mtn_momo', 'settlement_reference' => 'MTN-X', 'settlement_date' => '2026-10-15', 'currency' => 'XAF'], $h)->json('data.id');
    $this->postJson("/api/v1/clearing/batches/{$other}/items", ['payment_ids' => [$f['payment']->id]], $h)->assertStatus(422);

    $this->postJson("/api/v1/clearing/batches/{$batchId}/settle", ['settled_minor' => 122000, 'fee_minor' => 2500, 'bank_reference' => 'BNK-778'], $h)->assertOk()->assertJsonPath('data.status', 'SETTLED');
    $this->getJson('/api/v1/clearing/suspense', $h)->assertOk()->assertJsonCount(0, 'data'); // everything received is bank-settled
    $this->postJson("/api/v1/clearing/batches/{$batchId}/reconcile", [], $h)->assertStatus(422); // settler ≠ reconciler

    Passport::actingAs($checker);
    $this->postJson("/api/v1/clearing/batches/{$batchId}/reconcile", ['notes' => 'short 500'], $h)->assertOk()
        ->assertJsonPath('data.status', 'VARIANCE')->assertJsonPath('data.variance_minor', -500);
    $this->getJson('/api/v1/clearing/suspense', $h)->assertOk()->assertJsonPath('data.0.variance_minor', -500);
    $this->getJson('/api/v1/clearing/batches?status=VARIANCE', $h)->assertOk()->assertJsonPath('meta.total', 1);
    expect(ClearingBatch::findOrFail($batchId)->reconciled_by)->toBe($checker->id)
        ->and(DB::table('outbox_messages')->where('aggregate_id', $batchId)->pluck('event_name')->all())->toBe(['payment.clearing.settled', 'payment.clearing.reconciled']);
});
