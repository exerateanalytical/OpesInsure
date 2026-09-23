<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\MobileOAuthClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(MobileOAuthClientSeeder::class));

require_once __DIR__.'/Concerns/mobile_auth_helpers.php';

/**
 * The app previously had no way for a new customer to create an account at
 * all — only OTP login for a phone number that already exists as a User.
 * Registration (POST /public/accounts) existed but created a User stuck at
 * status PENDING_VERIFICATION forever, since verifyOtp only ever accepted
 * ACTIVE, and nothing else provisioned a Party or TenantMembership for a
 * self-registered account — so even an activated user would have landed on
 * an empty "no active workspace" screen.
 */
it('registers, then completes the first OTP verification as the activation step', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

    $registered = $this->postJson('/api/v1/public/accounts', [
        'full_name' => 'New Customer',
        'phone_e164' => '+237671234567',
        'password' => 'a-strong-password-12',
        'password_confirmation' => 'a-strong-password-12',
        'locale' => 'en',
        'terms_version' => '2026-01-01',
    ]);

    $registered->assertStatus(201);
    expect($registered->json('data.status'))->toBe('PENDING_VERIFICATION');

    $user = User::where('phone_e164', '+237671234567')->firstOrFail();

    // A freshly registered account cannot sign in until this OTP round trip
    // proves phone ownership.
    $requested = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => '+237671234567']);
    $requested->assertStatus(200);
    $code = extractMobileOtpCode();

    $verified = $this->postJson('/api/v1/auth/mobile/otp/verify', [
        'challenge_id' => $requested->json('data.challenge_id'),
        'code' => $code,
        'device_fingerprint' => 'new-device',
        'device_name' => 'Test Phone',
        'platform' => 'android',
    ]);

    $verified->assertStatus(201);
    expect($verified->json('data.access_token'))->not->toBeEmpty();

    $user->refresh();
    expect($user->status)->toBe('ACTIVE');
    expect($user->phone_verified_at)->not->toBeNull();
    expect($user->party_id)->not->toBeNull();

    // The workspace the app's role picker needs — not just an ACTIVE user.
    expect($verified->json('data.workspaces'))->toHaveCount(1);
    expect($verified->json('data.workspaces.0.role_code'))->toBe('CUSTOMER');

    $party = DB::table('parties')->where('id', $user->party_id)->first();
    expect($party)->not->toBeNull();
    expect($party->display_name)->toBe('New Customer');

    $contact = DB::table('party_contacts')->where('party_id', $user->party_id)->first();
    expect($contact->normalized_value)->toBe('+237671234567');
});

it('does not re-provision on a second login', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

    $this->postJson('/api/v1/public/accounts', [
        'full_name' => 'Repeat Customer',
        'phone_e164' => '+237671234568',
        'password' => 'a-strong-password-12',
        'password_confirmation' => 'a-strong-password-12',
        'locale' => 'en',
        'terms_version' => '2026-01-01',
    ])->assertStatus(201);

    $requested = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => '+237671234568']);
    $this->postJson('/api/v1/auth/mobile/otp/verify', [
        'challenge_id' => $requested->json('data.challenge_id'),
        'code' => extractMobileOtpCode(),
        'device_fingerprint' => 'device-1',
    ])->assertStatus(201);

    $user = User::where('phone_e164', '+237671234568')->firstOrFail();
    $originalPartyId = $user->party_id;

    $requested2 = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => '+237671234568']);
    $this->postJson('/api/v1/auth/mobile/otp/verify', [
        'challenge_id' => $requested2->json('data.challenge_id'),
        'code' => extractMobileOtpCode(),
        'device_fingerprint' => 'device-2',
    ])->assertStatus(201);

    expect($user->fresh()->party_id)->toBe($originalPartyId);
    expect(DB::table('parties')->where('id', $originalPartyId)->count())->toBe(1);
    expect(DB::table('tenant_memberships')->where('user_id', $user->id)->count())->toBe(1);
});

it('still refuses a suspended user even with the correct code', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

    $user = makeMobileTestUser();
    $user->forceFill(['status' => 'SUSPENDED'])->save();

    $requested = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => $user->phone_e164]);
    $verified = $this->postJson('/api/v1/auth/mobile/otp/verify', [
        'challenge_id' => $requested->json('data.challenge_id'),
        'code' => extractMobileOtpCode(),
        'device_fingerprint' => 'device-x',
    ]);

    $verified->assertStatus(422);
    expect($user->fresh()->status)->toBe('SUSPENDED');
});
