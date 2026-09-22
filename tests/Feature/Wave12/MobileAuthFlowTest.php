<?php

declare(strict_types=1);

use App\Models\UserDevice;
use App\Models\VerificationChallenge;
use Database\Seeders\MobileOAuthClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(MobileOAuthClientSeeder::class));

require_once __DIR__.'/Concerns/mobile_auth_helpers.php';

it('sends an OTP SMS only when the phone belongs to a real user, with an identical response either way', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    makeMobileTestUser('+237670000001');

    $known = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => '+237670000001']);
    $unknown = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => '+237699999999']);

    $known->assertStatus(200);
    $unknown->assertStatus(200);
    expect(array_keys($known->json('data')))->toEqualCanonicalizing(array_keys($unknown->json('data')));
    expect($known->json('data.delivery_status'))->toBe($unknown->json('data.delivery_status'));

    Http::assertSentCount(1); // only the known number actually triggers Twilio
});

it('never returns the challenge without creating a hashed, expiring verification_challenges row', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    $user = makeMobileTestUser();

    $response = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => $user->phone_e164]);

    $challenge = VerificationChallenge::findOrFail($response->json('data.challenge_id'));
    expect($challenge->user_id)->toBe($user->id);
    expect(strlen($challenge->code_hash))->toBeGreaterThan(20); // a real hash, not a raw 6-digit code
    expect($challenge->expires_at->isFuture())->toBeTrue();
});

it('verifies a correct OTP, issues tokens, and returns the workspace bootstrap', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    $user = makeMobileTestUser();
    [$tenant] = makeMobileTestWorkspace($user, ['broker.bordereaux.manage'], 'BROKER_STAFF');

    $requested = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => $user->phone_e164]);
    $code = extractMobileOtpCode();

    $verified = $this->postJson('/api/v1/auth/mobile/otp/verify', [
        'challenge_id' => $requested->json('data.challenge_id'),
        'code' => $code,
        'device_fingerprint' => 'device-abc',
        'device_name' => 'Test Phone',
        'platform' => 'android',
    ]);

    $verified->assertStatus(201);
    expect($verified->json('data.access_token'))->not->toBeEmpty();
    expect($verified->json('data.refresh_token'))->not->toBeEmpty();
    expect($verified->json('data.user.id'))->toBe($user->id);
    expect($verified->json('data.workspaces.0.tenant_id'))->toBe($tenant->id);
    expect($verified->json('data.workspaces.0.role_code'))->toBe('BROKER_STAFF');
    expect($verified->json('data.workspaces.0.permissions'))->toContain('broker.bordereaux.manage');

    $device = UserDevice::where('user_id', $user->id)->where('device_fingerprint', 'device-abc')->first();
    expect($device)->not->toBeNull();
    expect($device->trusted_at)->not->toBeNull();

    // The issued access token actually authenticates a subsequent request.
    $this->withHeader('Authorization', 'Bearer '.$verified->json('data.access_token'))
        ->getJson('/api/v1/auth/mobile/session')
        ->assertStatus(200)
        ->assertJsonPath('data.user.id', $user->id);
});

it('rejects the wrong code without revealing whether the code was close', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    $user = makeMobileTestUser();

    $requested = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => $user->phone_e164]);

    $response = $this->postJson('/api/v1/auth/mobile/otp/verify', [
        'challenge_id' => $requested->json('data.challenge_id'),
        'code' => '000000',
        'device_fingerprint' => 'device-abc',
    ]);

    $response->assertStatus(422);
    expect(VerificationChallenge::findOrFail($requested->json('data.challenge_id'))->attempts)->toBe(1);
});

it('locks the challenge after max_attempts wrong codes even if the right code is tried afterward', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    $user = makeMobileTestUser();

    $requested = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => $user->phone_e164]);
    $code = extractMobileOtpCode();
    $challengeId = $requested->json('data.challenge_id');

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/mobile/otp/verify', ['challenge_id' => $challengeId, 'code' => '000000', 'device_fingerprint' => 'd'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/mobile/otp/verify', ['challenge_id' => $challengeId, 'code' => $code, 'device_fingerprint' => 'd'])
        ->assertStatus(422);
});

it('rejects an already-consumed challenge (no replaying a successful verification)', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    $user = makeMobileTestUser();

    $requested = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => $user->phone_e164]);
    $code = extractMobileOtpCode();
    $payload = ['challenge_id' => $requested->json('data.challenge_id'), 'code' => $code, 'device_fingerprint' => 'd'];

    $this->postJson('/api/v1/auth/mobile/otp/verify', $payload)->assertStatus(201);
    $this->postJson('/api/v1/auth/mobile/otp/verify', $payload)->assertStatus(422);
});

it('rejects verification for a suspended user even with the correct code', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    $user = makeMobileTestUser(status: 'SUSPENDED');

    $requested = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => $user->phone_e164]);
    // A suspended user still gets an SMS today (status isn't checked at request time) —
    // the block happens at verification, which is what actually matters.
    $code = extractMobileOtpCode();

    $this->postJson('/api/v1/auth/mobile/otp/verify', [
        'challenge_id' => $requested->json('data.challenge_id'),
        'code' => $code,
        'device_fingerprint' => 'd',
    ])->assertStatus(422);
});

it('preserves a device trusted_at across repeat logins instead of resetting it', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    $user = makeMobileTestUser();

    $first = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => $user->phone_e164]);
    $code1 = extractMobileOtpCode();
    $this->postJson('/api/v1/auth/mobile/otp/verify', ['challenge_id' => $first->json('data.challenge_id'), 'code' => $code1, 'device_fingerprint' => 'device-abc'])->assertStatus(201);
    $firstTrustedAt = UserDevice::where('device_fingerprint', 'device-abc')->first()->trusted_at;

    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM2'], 201)]);
    $second = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => $user->phone_e164]);
    $code2 = extractMobileOtpCode();
    $this->postJson('/api/v1/auth/mobile/otp/verify', ['challenge_id' => $second->json('data.challenge_id'), 'code' => $code2, 'device_fingerprint' => 'device-abc'])->assertStatus(201);

    $secondTrustedAt = UserDevice::where('device_fingerprint', 'device-abc')->first()->trusted_at;
    expect($secondTrustedAt->equalTo($firstTrustedAt))->toBeTrue();
    expect(UserDevice::where('user_id', $user->id)->count())->toBe(1); // same device row reused, not duplicated
});

it('rejects an unauthenticated call to /session', function () {
    $this->getJson('/api/v1/auth/mobile/session')->assertStatus(401);
});
