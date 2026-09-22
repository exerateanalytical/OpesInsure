<?php

declare(strict_types=1);

use App\Models\MobileRefreshToken;
use Database\Seeders\MobileOAuthClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(MobileOAuthClientSeeder::class));

require_once __DIR__.'/Concerns/mobile_auth_helpers.php';

/** @return array{access_token: string, refresh_token: string} */
function loginMobileTestUser(): array
{
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    $user = makeMobileTestUser();

    $requested = test()->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => $user->phone_e164]);
    $code = extractMobileOtpCode();

    $verified = test()->postJson('/api/v1/auth/mobile/otp/verify', [
        'challenge_id' => $requested->json('data.challenge_id'),
        'code' => $code,
        'device_fingerprint' => 'device-abc',
    ]);

    return ['access_token' => $verified->json('data.access_token'), 'refresh_token' => $verified->json('data.refresh_token')];
}

it('rotates the refresh token on use and the old one no longer works', function () {
    $tokens = loginMobileTestUser();

    $rotated = $this->postJson('/api/v1/auth/mobile/refresh', ['refresh_token' => $tokens['refresh_token']]);

    $rotated->assertStatus(200);
    expect($rotated->json('data.refresh_token'))->not->toBe($tokens['refresh_token']);

    $reuse = $this->postJson('/api/v1/auth/mobile/refresh', ['refresh_token' => $tokens['refresh_token']]);
    $reuse->assertStatus(422);
});

it('revokes the entire refresh-token family when a rotated token is replayed (theft signal)', function () {
    $tokens = loginMobileTestUser();

    $rotated = $this->postJson('/api/v1/auth/mobile/refresh', ['refresh_token' => $tokens['refresh_token']])->json('data');

    // Someone replays the OLD (already-rotated) token — e.g. an attacker who
    // stole it before the legitimate rotation. This must burn the whole
    // chain, including the token the legitimate client just received.
    $this->postJson('/api/v1/auth/mobile/refresh', ['refresh_token' => $tokens['refresh_token']])->assertStatus(422);

    $legitimateRetry = $this->postJson('/api/v1/auth/mobile/refresh', ['refresh_token' => $rotated['refresh_token']]);
    $legitimateRetry->assertStatus(422);

    expect(MobileRefreshToken::where('user_id', '!=', null)->whereNull('revoked_at')->count())->toBe(0);
});

it('rejects an unknown refresh token', function () {
    $this->postJson('/api/v1/auth/mobile/refresh', ['refresh_token' => 'not-a-real-token'])->assertStatus(422);
});

it('rejects an expired refresh token without revoking the rest of the family', function () {
    $tokens = loginMobileTestUser();
    $record = MobileRefreshToken::where('token_hash', hash('sha256', $tokens['refresh_token']))->firstOrFail();
    $record->update(['expires_at' => now()->subMinute()]);

    $this->postJson('/api/v1/auth/mobile/refresh', ['refresh_token' => $tokens['refresh_token']])->assertStatus(422);

    expect($record->refresh()->revoked_at)->toBeNull(); // natural expiry isn't treated as an attack
});

it('logs out: revokes the current access token and the refresh family for that device only', function () {
    $tokens = loginMobileTestUser();

    // A second, independent device/session for the same user.
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM2'], 201)]);
    $user = App\Models\User::where('phone_e164', '+237670000000')->firstOrFail();
    $secondRequest = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => $user->phone_e164]);
    $secondCode = extractMobileOtpCode();
    $secondTokens = $this->postJson('/api/v1/auth/mobile/otp/verify', [
        'challenge_id' => $secondRequest->json('data.challenge_id'),
        'code' => $secondCode,
        'device_fingerprint' => 'device-xyz',
    ])->json('data');

    $this->withHeader('Authorization', 'Bearer '.$tokens['access_token'])
        ->postJson('/api/v1/auth/mobile/logout', ['refresh_token' => $tokens['refresh_token']])
        ->assertStatus(200);

    // Passport's TokenGuard caches its resolved user for the guard
    // instance's lifetime, and AuthManager caches the guard instance for
    // the app container's lifetime — which spans this whole test method,
    // not per simulated request. A real HTTP request gets a fresh process
    // every time, so this reset only matters here, not in production.
    $this->app['auth']->forgetGuards();

    // The logged-out device's access token no longer authenticates.
    $this->withHeader('Authorization', 'Bearer '.$tokens['access_token'])
        ->getJson('/api/v1/auth/mobile/session')
        ->assertStatus(401);

    // Its refresh token is dead too.
    $this->postJson('/api/v1/auth/mobile/refresh', ['refresh_token' => $tokens['refresh_token']])->assertStatus(422);

    $this->app['auth']->forgetGuards();

    // The OTHER device's session is completely unaffected.
    $this->withHeader('Authorization', 'Bearer '.$secondTokens['access_token'])
        ->getJson('/api/v1/auth/mobile/session')
        ->assertStatus(200);
    $this->postJson('/api/v1/auth/mobile/refresh', ['refresh_token' => $secondTokens['refresh_token']])->assertStatus(200);
});
