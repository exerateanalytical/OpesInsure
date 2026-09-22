<?php

declare(strict_types=1);

use App\Models\PaymentIntentRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';

it('lists only the authenticated customer\'s own payments, newest first', function () {
    $fixture = makeMobileCustomerFixture();
    $mine = makeMobileTestPayment($fixture['proposal'], $fixture['tenant']);

    $otherFixture = makeMobileCustomerFixture('+237670000099');
    makeMobileTestPayment($otherFixture['proposal'], $fixture['tenant']);

    Passport::actingAs($fixture['user']);

    $response = $this->getJson('/api/v1/mobile/payments', tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.data'))->toHaveCount(1);
    expect($response->json('data.data.0.id'))->toBe($mine->id);
});

it('shows a single owned payment, and 403s (not 404) for one that exists but belongs to someone else', function () {
    $fixture = makeMobileCustomerFixture();
    $mine = makeMobileTestPayment($fixture['proposal'], $fixture['tenant']);

    $otherFixture = makeMobileCustomerFixture('+237670000098');
    $theirs = makeMobileTestPayment($otherFixture['proposal'], $fixture['tenant']);

    Passport::actingAs($fixture['user']);

    $this->getJson("/api/v1/mobile/payments/{$mine->id}", tenantHeaderFor($fixture['tenant']))->assertStatus(200);
    $this->getJson("/api/v1/mobile/payments/{$theirs->id}", tenantHeaderFor($fixture['tenant']))->assertStatus(403);
    $this->getJson('/api/v1/mobile/payments/'.Str::uuid(), tenantHeaderFor($fixture['tenant']))->assertStatus(404);
});

it('retries a failed payment but refuses to retry one that already succeeded', function () {
    $fixture = makeMobileCustomerFixture();
    $failed = makeMobileTestPayment($fixture['proposal'], $fixture['tenant'], ['provider' => 'fake', 'status' => 'FAILED']);
    $succeeded = makeMobileTestPayment($fixture['proposal'], $fixture['tenant'], ['status' => 'SUCCEEDED', 'idempotency_key' => (string) Str::uuid()]);

    Passport::actingAs($fixture['user']);

    $retried = $this->postJson("/api/v1/mobile/payments/{$failed->id}/retry", [], tenantHeaderFor($fixture['tenant']));
    $retried->assertStatus(202);
    expect($retried->json('data.status'))->toBe('PENDING_CUSTOMER');

    $this->postJson("/api/v1/mobile/payments/{$succeeded->id}/retry", [], tenantHeaderFor($fixture['tenant']))->assertStatus(422);
});

it('returns a JSON receipt for an owned payment', function () {
    $fixture = makeMobileCustomerFixture();
    $payment = makeMobileTestPayment($fixture['proposal'], $fixture['tenant']);

    Passport::actingAs($fixture['user']);

    $response = $this->getJson("/api/v1/mobile/payments/{$payment->id}/receipt", tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.id'))->toBe($payment->id);
    expect($response->json('data.amount_minor'))->toBe(100000);
    expect($response->json('data.status'))->toBe('SUCCEEDED');
});

it('lets a customer request a refund on their own successful payment without the staff refund.request permission', function () {
    $fixture = makeMobileCustomerFixture();
    $payment = makeMobileTestPayment($fixture['proposal'], $fixture['tenant']);

    Passport::actingAs($fixture['user']); // no permissions granted at all

    $response = $this->postJson("/api/v1/mobile/payments/{$payment->id}/refunds", [
        'amount_minor' => 50000,
        'reason_code' => 'CUSTOMER_REQUEST',
        'idempotency_key' => (string) Str::uuid(),
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(201);
    expect($response->json('data.status'))->toBe('REQUESTED');
    expect(DB::table('refunds')->where('payment_intent_id', $payment->id)->count())->toBe(1);
});

it('refuses a refund request for a payment belonging to another customer', function () {
    $fixture = makeMobileCustomerFixture();
    $otherFixture = makeMobileCustomerFixture('+237670000097');
    $theirs = makeMobileTestPayment($otherFixture['proposal'], $fixture['tenant']);

    Passport::actingAs($fixture['user']);

    $this->postJson("/api/v1/mobile/payments/{$theirs->id}/refunds", [
        'amount_minor' => 10000,
        'reason_code' => 'CUSTOMER_REQUEST',
        'idempotency_key' => (string) Str::uuid(),
    ], tenantHeaderFor($fixture['tenant']))->assertStatus(403);
});

it('rejects an unauthenticated request to list payments', function () {
    $this->getJson('/api/v1/mobile/payments')->assertStatus(401);
});
