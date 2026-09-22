<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_money_helpers.php';

it('ignores a forged SUCCESS in the notification query string and trusts only the re-queried FAILED status', function () {
    $intent = makeMobileMoneyTestIntent('orange_money', 'pay-token-xyz');

    Http::fake([
        '*/oauth/v3/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*/orange-money-webpay/*/v1/transactionstatus' => Http::response(['status' => 'FAILED'], 200),
    ]);

    $response = $this->get("/api/v1/webhooks/payments/orange-money/callback?order_id={$intent->id}&status=SUCCESS&token=testing-orange-callback-token");

    $response->assertStatus(200)->assertJson(['accepted' => true]);
    expect($intent->refresh()->status)->toBe('FAILED');

    Http::assertSent(function ($request) use ($intent) {
        return str_contains($request->url(), '/transactionstatus')
            && $request['order_id'] === $intent->id
            && $request['pay_token'] === 'pay-token-xyz';
    });
});

it('transitions to SUCCEEDED when Orange transactionstatus authoritatively reports SUCCESS', function () {
    $intent = makeMobileMoneyTestIntent('orange_money', 'pay-token-xyz');

    Http::fake([
        '*/oauth/v3/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*/orange-money-webpay/*/v1/transactionstatus' => Http::response(['status' => 'SUCCESS'], 200),
    ]);

    $this->get("/api/v1/webhooks/payments/orange-money/callback?order_id={$intent->id}&status=FAILED&token=testing-orange-callback-token")
        ->assertStatus(200);

    // Notification claimed FAILED; the authoritative re-query said SUCCESS — SUCCESS wins.
    expect($intent->refresh()->status)->toBe('SUCCEEDED');
});

it('rejects a callback with an invalid token', function () {
    $intent = makeMobileMoneyTestIntent('orange_money', 'pay-token-xyz');

    Http::fake();

    $response = $this->get("/api/v1/webhooks/payments/orange-money/callback?order_id={$intent->id}&status=SUCCESS&token=wrong-token");

    $response->assertStatus(401);
    expect($intent->refresh()->status)->toBe('PENDING_CUSTOMER');
    Http::assertNothingSent();
});

it('acks an unrecognised order id without error', function () {
    Http::fake();

    $response = $this->get('/api/v1/webhooks/payments/orange-money/callback?order_id=00000000-0000-0000-0000-000000000000&status=SUCCESS&token=testing-orange-callback-token');

    $response->assertStatus(200)->assertJson(['accepted' => true]);
    Http::assertNothingSent();
});

it('is idempotent: repeated callbacks after SUCCEEDED do not duplicate the state transition', function () {
    $intent = makeMobileMoneyTestIntent('orange_money', 'pay-token-xyz');

    Http::fake([
        '*/oauth/v3/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*/orange-money-webpay/*/v1/transactionstatus' => Http::response(['status' => 'SUCCESS'], 200),
    ]);

    $url = "/api/v1/webhooks/payments/orange-money/callback?order_id={$intent->id}&status=SUCCESS&token=testing-orange-callback-token";
    $this->get($url)->assertStatus(200);
    $this->get($url)->assertStatus(200);

    expect($intent->refresh()->status)->toBe('SUCCEEDED');
    expect(DB::table('payment_events')->where('payment_intent_id', $intent->id)->count())->toBe(1);
});
