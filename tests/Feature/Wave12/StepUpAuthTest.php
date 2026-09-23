<?php

declare(strict_types=1);

use App\Models\StepUpGrant;
use App\Models\VerificationChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/Concerns/mobile_auth_helpers.php';

// Both step-up routes carry the 'idempotency' middleware (the real Expo
// client's api() helper sends a fresh Idempotency-Key on every
// idempotent:true call, and step-up request/verify both are — see
// overlay/src/api/client.ts's StepUpApi), so every call in this file needs
// its own key. A fresh UUID per call, not a fixed one, since several tests
// deliberately send multiple *different* bodies (e.g. wrong-code retries)
// against the same route — reusing one key across those would hit
// IdempotencyGuard's conflict response instead of the behaviour under test.
if (! function_exists('idemHeader')) {
    function idemHeader(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }
}

it('issues a step-up challenge by SMS for an allowlisted purpose', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/security/step-up/request', ['purpose' => 'PAYMENT_REFUND_REQUEST'], tenantHeaderFor($fixture['tenant']) + idemHeader());

    $response->assertStatus(200);
    expect($response->json('data.challenge_id'))->not->toBeEmpty();
    expect($response->json('data.delivery_hint'))->toBe('SMS');
    expect($response->json('data.expires_in'))->toBeInt();

    $challenge = VerificationChallenge::findOrFail($response->json('data.challenge_id'));
    expect($challenge->purpose)->toBe('SU:PAYMENT_REFUND_REQUEST');
    expect($challenge->user_id)->toBe($fixture['user']->id);
    Http::assertSentCount(1);
});

it('rejects a step-up request for a purpose outside the allowlist', function () {
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $this->postJson('/api/v1/mobile/security/step-up/request', ['purpose' => 'DELETE_ACCOUNT'], tenantHeaderFor($fixture['tenant']) + idemHeader())
        ->assertStatus(422);
});

it('rejects an unauthenticated step-up request', function () {
    $this->postJson('/api/v1/mobile/security/step-up/request', ['purpose' => 'PAYMENT_REFUND_REQUEST'], idemHeader())->assertStatus(401);
});

it('verifies a correct step-up code and returns a single-purpose grant token', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $requested = $this->postJson('/api/v1/mobile/security/step-up/request', ['purpose' => 'PAYMENT_REFUND_REQUEST'], tenantHeaderFor($fixture['tenant']) + idemHeader());
    $code = extractMobileOtpCode();

    $verified = $this->postJson('/api/v1/mobile/security/step-up/verify', [
        'challenge_id' => $requested->json('data.challenge_id'),
        'purpose' => 'PAYMENT_REFUND_REQUEST',
        'code' => $code,
    ], tenantHeaderFor($fixture['tenant']) + idemHeader());

    $verified->assertStatus(201);
    expect($verified->json('data.grant_token'))->not->toBeEmpty();
    expect($verified->json('data.purpose'))->toBe('PAYMENT_REFUND_REQUEST');
    expect($verified->json('data.expires_at'))->not->toBeEmpty();

    $grant = StepUpGrant::where('user_id', $fixture['user']->id)->first();
    expect($grant)->not->toBeNull();
    expect($grant->tenant_id)->toBe($fixture['tenant']->id);
    expect($grant->purpose)->toBe('PAYMENT_REFUND_REQUEST');
    expect($grant->token_hash)->toBe(hash('sha256', $verified->json('data.grant_token')));
    expect($grant->consumed_at)->toBeNull();
});

it('rejects the wrong step-up code and counts the attempt', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $requested = $this->postJson('/api/v1/mobile/security/step-up/request', ['purpose' => 'PAYMENT_REFUND_REQUEST'], tenantHeaderFor($fixture['tenant']) + idemHeader());

    $response = $this->postJson('/api/v1/mobile/security/step-up/verify', [
        'challenge_id' => $requested->json('data.challenge_id'),
        'purpose' => 'PAYMENT_REFUND_REQUEST',
        'code' => '000000',
    ], tenantHeaderFor($fixture['tenant']) + idemHeader());

    $response->assertStatus(422);
    expect(VerificationChallenge::findOrFail($requested->json('data.challenge_id'))->attempts)->toBe(1);
    expect(StepUpGrant::count())->toBe(0);
});

it('locks the challenge after max_attempts wrong codes even if the right code is tried afterward', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $requested = $this->postJson('/api/v1/mobile/security/step-up/request', ['purpose' => 'PAYMENT_REFUND_REQUEST'], tenantHeaderFor($fixture['tenant']) + idemHeader());
    $code = extractMobileOtpCode();
    $challengeId = $requested->json('data.challenge_id');

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/mobile/security/step-up/verify', [
            'challenge_id' => $challengeId, 'purpose' => 'PAYMENT_REFUND_REQUEST', 'code' => '000000',
        ], tenantHeaderFor($fixture['tenant']) + idemHeader())->assertStatus(422);
    }

    $this->postJson('/api/v1/mobile/security/step-up/verify', [
        'challenge_id' => $challengeId, 'purpose' => 'PAYMENT_REFUND_REQUEST', 'code' => $code,
    ], tenantHeaderFor($fixture['tenant']) + idemHeader())->assertStatus(422);

    expect(StepUpGrant::count())->toBe(0);
});

it('rejects replaying an already-consumed step-up challenge', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $requested = $this->postJson('/api/v1/mobile/security/step-up/request', ['purpose' => 'PAYMENT_REFUND_REQUEST'], tenantHeaderFor($fixture['tenant']) + idemHeader());
    $code = extractMobileOtpCode();
    $payload = ['challenge_id' => $requested->json('data.challenge_id'), 'purpose' => 'PAYMENT_REFUND_REQUEST', 'code' => $code];

    $this->postJson('/api/v1/mobile/security/step-up/verify', $payload, tenantHeaderFor($fixture['tenant']) + idemHeader())->assertStatus(201);
    $this->postJson('/api/v1/mobile/security/step-up/verify', $payload, tenantHeaderFor($fixture['tenant']) + idemHeader())->assertStatus(422);

    expect(StepUpGrant::count())->toBe(1);
});

it('rejects a login OTP challenge presented to the step-up verify endpoint (cross-purpose reuse)', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    // A real MOBILE_LOGIN challenge, not a step-up one.
    $loginRequested = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => $fixture['user']->phone_e164]);
    $loginCode = extractMobileOtpCode();

    $this->postJson('/api/v1/mobile/security/step-up/verify', [
        'challenge_id' => $loginRequested->json('data.challenge_id'),
        'purpose' => 'PAYMENT_REFUND_REQUEST',
        'code' => $loginCode,
    ], tenantHeaderFor($fixture['tenant']) + idemHeader())->assertStatus(422);

    expect(StepUpGrant::count())->toBe(0);
});

it('rejects a step-up verify whose purpose does not match the purpose the challenge was requested for', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $requested = $this->postJson('/api/v1/mobile/security/step-up/request', ['purpose' => 'PAYMENT_REFUND_REQUEST'], tenantHeaderFor($fixture['tenant']) + idemHeader());
    $code = extractMobileOtpCode();

    $this->postJson('/api/v1/mobile/security/step-up/verify', [
        'challenge_id' => $requested->json('data.challenge_id'),
        'purpose' => 'COMMISSION_WITHDRAWAL',
        'code' => $code,
    ], tenantHeaderFor($fixture['tenant']) + idemHeader())->assertStatus(422);

    expect(StepUpGrant::count())->toBe(0);
});
