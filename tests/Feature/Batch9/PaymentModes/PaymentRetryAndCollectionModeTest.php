<?php

declare(strict_types=1);

use App\Application\Distribution\Execution\ExecutionOutcome;
use App\Application\Distribution\Execution\ExecutionPlanner;
use App\Application\Payments\ExecutionModes\BankPaymentExecution;
use App\Application\Payments\ExecutionModes\BrokerCollectionPaymentExecution;
use App\Application\Payments\ExecutionModes\ExternalProviderPaymentExecution;
use App\Application\Payments\ExecutionModes\InsurerCollectionPaymentExecution;
use App\Application\Payments\ExecutionModes\MobileMoneyPaymentExecution;
use App\Application\Payments\ExecutionModes\PaymentExecutionRegistry;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntentRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function b94FailedPayment(array $f, int $attempts = 1, array $overrides = []): PaymentIntentRecord
{
    $p = makeMobileTestPayment($f['proposal'], $f['tenant'], ['provider' => 'fake', 'status' => 'FAILED', ...$overrides]);
    for ($n = 1; $n <= $attempts; $n++) {
        PaymentAttempt::create(['payment_intent_id' => $p->id, 'attempt_number' => $n, 'provider_request_id' => (string) Str::uuid(), 'status' => 'FAILED', 'failure_code' => 'PAYER_DECLINED', 'started_at' => now(), 'completed_at' => now()]);
    }

    return $p;
}

function b94Profile(string $carrierId, string $mode, string $exec, array $config = []): string
{
    $user = User::create(['full_name' => 'B94 '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $id = (string) Str::uuid();
    DB::table('carrier_capability_profiles')->insert(['id' => $id, 'carrier_id' => $carrierId, 'version' => 1, 'status' => 'ACTIVE', 'effective_from' => now()->subDay(),
        'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('carrier_capability_modes')->insert(['id' => (string) Str::uuid(), 'profile_id' => $id, 'capability' => 'PAYMENT', 'mode' => $mode, 'execution_mode' => $exec,
        'config' => json_encode($config), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

it('REQ-PAY-008 retries a failed payment as a new attempt under the same intent with a customer message', function () {
    $f = makeMobileCustomerFixture();
    $p = b94FailedPayment($f);
    $first = $p->attempts()->first();
    Passport::actingAs($f['user']);

    $r = $this->postJson("/api/v1/payments/{$p->id}/retry", [], tenantHeaderFor($f['tenant']))->assertStatus(202)
        ->assertJsonPath('data.id', $p->id)->assertJsonPath('data.status', 'PENDING_CUSTOMER')->assertJsonPath('meta.attempt_number', 2);
    expect($r->json('meta.customer_message'))->toContain('new payment request');
    $second = PaymentAttempt::where('payment_intent_id', $p->id)->where('attempt_number', 2)->firstOrFail();
    expect($second->retry_of_attempt_id)->toBe($first->id)->and($second->requested_by)->toBe($f['user']->id)->and($second->collection_mode)->toBe('MOBILE_MONEY')
        ->and(PaymentIntentRecord::where('proposal_id', $f['proposal']->id)->count())->toBe(1)
        ->and(DB::table('outbox_messages')->where('event_name', 'payment.retry.requested')->where('aggregate_id', $p->id)->exists())->toBeTrue();

    $this->getJson("/api/v1/payments/{$p->id}/attempts", tenantHeaderFor($f['tenant']))->assertOk()
        ->assertJsonPath('meta.attempts_used', 2)->assertJsonPath('meta.max_attempts', 3)->assertJsonPath('meta.retryable', false);
});

it('REQ-PAY-008 enforces the retry limit and records the exhaustion', function () {
    $f = makeMobileCustomerFixture();
    $p = b94FailedPayment($f, 2, ['max_attempts' => 2]);
    Passport::actingAs($f['user']);

    $this->postJson("/api/v1/payments/{$p->id}/retry", [], tenantHeaderFor($f['tenant']))->assertStatus(422)->assertJsonValidationErrors('attempts');
    expect($p->attempts()->count())->toBe(2)
        ->and(DB::table('outbox_messages')->where('event_name', 'payment.retry.exhausted')->where('aggregate_id', $p->id)->exists())->toBeTrue();
    $this->postJson("/api/v1/mobile/payments/{$p->id}/retry", [], tenantHeaderFor($f['tenant']))->assertStatus(422);
});

it('REQ-PAY-008 never double charges: idempotent replay, paid payment and covered obligation are refused', function () {
    $f = makeMobileCustomerFixture();
    $h = tenantHeaderFor($f['tenant']);
    Passport::actingAs($f['user']);

    $p = b94FailedPayment($f);
    $key = 'retry-'.Str::random(20);
    $this->postJson("/api/v1/payments/{$p->id}/retry", ['idempotency_key' => $key], $h)->assertStatus(202);
    $this->postJson("/api/v1/payments/{$p->id}/retry", ['idempotency_key' => $key], $h)->assertOk()->assertJsonPath('meta.replayed', true);
    expect($p->attempts()->count())->toBe(2);

    $paid = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'SUCCEEDED']);
    $this->postJson("/api/v1/payments/{$paid->id}/retry", [], $h)->assertStatus(422)->assertJsonValidationErrors('status');

    $obligation = (string) Str::uuid();
    makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'SUCCEEDED', 'financial_obligation_id' => $obligation]);
    $failed = b94FailedPayment($f, 1, ['financial_obligation_id' => $obligation, 'idempotency_key' => (string) Str::uuid()]);
    $this->postJson("/api/v1/payments/{$failed->id}/retry", [], $h)->assertStatus(422)->assertJsonValidationErrors('financial_obligation_id');
    expect($failed->attempts()->count())->toBe(1);
});

it('REQ-PAY-014 records the collection mode from the carrier capability profile, pinned, and refuses an on-platform prompt', function () {
    $f = makeMobileCustomerFixture();
    $profile = b94Profile($f['carrier']->id, 'BROKER_COLLECTION', 'MANUAL', ['credited_account' => 'BROKER-TRUST-001']);
    $p = b94FailedPayment($f);
    Passport::actingAs($f['user']);

    $this->getJson("/api/v1/payments/{$p->id}/collection-mode", tenantHeaderFor($f['tenant']))->assertOk()
        ->assertJsonPath('data.collection_mode', 'BROKER_COLLECTION')->assertJsonPath('data.semantics.collector', 'BROKER')
        ->assertJsonPath('data.semantics.funds_holder', 'BROKER')->assertJsonPath('data.semantics.credited_account', 'BROKER-TRUST-001')
        ->assertJsonPath('data.semantics.requires_reconciliation', true)
        ->assertJsonPath('data.execution.adapter', BrokerCollectionPaymentExecution::class)->assertJsonPath('data.execution.status', ExecutionOutcome::AWAITING_CARRIER);
    expect(DB::table('capability_pins')->where(['subject_type' => 'payment_intent', 'subject_id' => $p->id, 'capability' => 'PAYMENT', 'mode' => 'BROKER_COLLECTION'])->exists())->toBeTrue()
        ->and(DB::table('outbox_messages')->where('event_name', 'payment.collection_mode.assigned')->count())->toBe(1);

    // A later profile change does not re-route money already in flight.
    DB::table('carrier_capability_modes')->where('profile_id', $profile)->update(['mode' => 'MOBILE_MONEY', 'execution_mode' => 'CONFIGURED']);
    $this->getJson("/api/v1/payments/{$p->id}/collection-mode", tenantHeaderFor($f['tenant']))->assertJsonPath('data.collection_mode', 'BROKER_COLLECTION');

    $this->postJson("/api/v1/payments/{$p->id}/retry", [], tenantHeaderFor($f['tenant']))->assertStatus(422)->assertJsonValidationErrors('collection_mode');
    expect($p->attempts()->count())->toBe(1);

    // A new payment follows the new profile.
    $next = b94FailedPayment($f, 1, ['idempotency_key' => (string) Str::uuid()]);
    $this->getJson("/api/v1/payments/{$next->id}/collection-mode", tenantHeaderFor($f['tenant']))->assertJsonPath('data.collection_mode', 'MOBILE_MONEY')
        ->assertJsonPath('data.semantics.on_platform_prompt', true)->assertJsonPath('data.execution.adapter', MobileMoneyPaymentExecution::class);
});

it('REQ-PAY-014 REQ-AOM-002 wraps payment execution in the capability-pinned adapter registry', function () {
    $registry = app(PaymentExecutionRegistry::class);
    $expect = ['BROKER_COLLECTION' => [BrokerCollectionPaymentExecution::class, 'MANUAL'], 'INSURER_COLLECTION' => [InsurerCollectionPaymentExecution::class, 'MANUAL'],
        'MOBILE_MONEY' => [MobileMoneyPaymentExecution::class, 'CONFIGURED'], 'BANK' => [BankPaymentExecution::class, 'CONFIGURED'], 'EXTERNAL' => [ExternalProviderPaymentExecution::class, 'REMOTE_API']];
    foreach ($expect as $mode => [$class, $exec]) {
        $a = $registry->forCollectionMode($mode);
        expect($a)->toBeInstanceOf($class)->and($a->executionMode())->toBe($exec)->and($a->capability())->toBe('PAYMENT');
    }
    expect(ExecutionPlanner::PORTS)->toHaveKey('payment');

    $f = makeMobileCustomerFixture();
    expect(app(ExecutionPlanner::class)->plan($f['carrier']->id)['payment']['adapter'])->toBe(MobileMoneyPaymentExecution::class);
    b94Profile($f['carrier']->id, 'EXTERNAL_PROVIDER', 'REMOTE_API');
    expect(app(ExecutionPlanner::class)->plan($f['carrier']->id)['payment']['status'])->toBe(ExecutionOutcome::INTEGRATION_UNAVAILABLE);
});

it('turns a flagged duplicate reconciliation item into one requested refund (once Batch 9-5 is merged)', function () {
    $sink = 'App\\Application\\Reconciliation\\RefundCandidateSink';
    if (! interface_exists($sink)) {
        $this->markTestSkipped('RefundCandidateSink (Batch 9-5) not merged.');
    }
    expect(app($sink))->toBeInstanceOf(\App\Application\Finance\Refunds\DuplicatePaymentRefundCandidateSink::class);
    $f = makeMobileCustomerFixture();
    $paid = makeMobileTestPayment($f['proposal'], $f['tenant']);
    $import = \App\Models\ReconciliationImport::create(['tenant_id' => $f['tenant']->id, 'source_type' => 'PROVIDER', 'provider' => 'fake', 'statement_reference' => 'ST-'.Str::random(6),
        'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(), 'currency' => 'XAF', 'file_hash' => Str::random(64), 'status' => 'COMPLETED', 'uploaded_by' => $f['user']->id]);
    $item = \App\Models\ReconciliationItem::create(['reconciliation_import_id' => $import->id, 'external_reference' => 'DUP-1', 'transaction_at' => now(), 'gross_minor' => 100000, 'fee_minor' => 0,
        'net_minor' => 100000, 'currency' => 'XAF', 'status' => 'EXCEPTION', 'matched_type' => 'PAYMENT_INTENT', 'matched_id' => $paid->id, 'raw_data' => []]);
    app($sink)->refundCandidate($item, 'DUPLICATE_PAYMENT', 100000, 'XAF');
    app($sink)->refundCandidate($item, 'DUPLICATE_PAYMENT', 100000, 'XAF');
    expect(\App\Models\Refund::where('payment_intent_id', $paid->id)->where('status', 'REQUESTED')->count())->toBe(1);
});
