<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_money_helpers.php';

it('re-queries MTN status and transitions the intent to SUCCEEDED, ignoring the callback body entirely', function () {
    $referenceId = (string) Str::uuid();
    $intent = makeMobileMoneyTestIntent('mtn_momo', $referenceId);

    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL'], 200),
    ]);

    // The callback body is a deliberately WRONG/attacker-controlled status —
    // it must never be read, let alone trusted.
    $response = $this->postJson(
        "/api/v1/webhooks/payments/mtn-momo/callback?reference_id={$referenceId}&token=testing-mtn-callback-token",
        ['status' => 'FAILED', 'amount' => '1']
    );

    $response->assertStatus(200)->assertJson(['accepted' => true]);
    expect($intent->refresh()->status)->toBe('SUCCEEDED');

    Http::assertSent(fn ($request) => str_contains($request->url(), "/collection/v1_0/requesttopay/{$referenceId}"));
});

it('rejects a callback with an invalid token', function () {
    $referenceId = (string) Str::uuid();
    $intent = makeMobileMoneyTestIntent('mtn_momo', $referenceId);

    Http::fake(); // nothing should be called at all

    $response = $this->postJson("/api/v1/webhooks/payments/mtn-momo/callback?reference_id={$referenceId}&token=not-the-real-token");

    $response->assertStatus(401);
    expect($intent->refresh()->status)->toBe('PENDING_CUSTOMER');
    Http::assertNothingSent();
});

it('acks an unrecognised reference id without error', function () {
    Http::fake(); // nothing should be called: no matching intent to reconcile

    $response = $this->postJson('/api/v1/webhooks/payments/mtn-momo/callback?reference_id=does-not-exist&token=testing-mtn-callback-token');

    $response->assertStatus(200)->assertJson(['accepted' => true]);
    Http::assertNothingSent();
});

it('is idempotent: a second callback after SUCCEEDED does not error or duplicate the state transition', function () {
    $referenceId = (string) Str::uuid();
    $intent = makeMobileMoneyTestIntent('mtn_momo', $referenceId);

    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL'], 200),
    ]);

    $url = "/api/v1/webhooks/payments/mtn-momo/callback?reference_id={$referenceId}&token=testing-mtn-callback-token";
    $this->postJson($url)->assertStatus(200);
    $this->postJson($url)->assertStatus(200);

    expect($intent->refresh()->status)->toBe('SUCCEEDED');
    expect(DB::table('payment_events')->where('payment_intent_id', $intent->id)->count())->toBe(1);
});

it('leaves the intent untouched while MTN reports the payment still pending', function () {
    $referenceId = (string) Str::uuid();
    $intent = makeMobileMoneyTestIntent('mtn_momo', $referenceId);

    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'PENDING'], 200),
    ]);

    $this->postJson("/api/v1/webhooks/payments/mtn-momo/callback?reference_id={$referenceId}&token=testing-mtn-callback-token")
        ->assertStatus(200);

    expect($intent->refresh()->status)->toBe('PENDING_CUSTOMER');
});
