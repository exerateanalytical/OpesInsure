<?php

declare(strict_types=1);

/*
 | Mobile audit 2026-09-27 section B: device detail (B1), login-activity detail (B2), security alerts (B3), staff
 | security (B4), step-up purposes (B5) and Play Integrity verdicts (B6).
 */

use App\Application\Identity\RoleCatalogue;
use App\Models\{Role, Tenant, TenantMembership, User, UserDevice, UserNotification};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

// These tests pin the enforced behaviour; production holds it via mobile_runtime.step_up.not_enforced_yet during rollout.
beforeEach(fn () => config(['mobile_runtime.step_up.not_enforced_yet' => []]));

it('rollout hold: held purposes pass without a grant but a presented bad grant is still refused', function () {
    config(['mobile_runtime.step_up.not_enforced_yet' => ['SIGN_OUT_EVERYWHERE']]);
    $f = makeMobileCustomerFixture('+237670005551');
    Passport::actingAs($f['user']);
    $this->postJson('/api/v1/auth/mobile/logout-all', [], ['X-Step-Up-Grant' => 'not-a-real-grant'])->assertStatus(401);
    $this->postJson('/api/v1/auth/mobile/logout-all')->assertSuccessful();
});

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

const MSD_DEVICE = ['fingerprint' => 'msd-dev-1', 'name' => 'Pixel 8', 'platform' => 'android'];

function msdMember(Tenant $t, string $role, array $membership = [], string $phone = null): User
{
    $u = User::create(['full_name' => 'Staff '.Str::random(4), 'phone_e164' => $phone ?? '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::forceCreate(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE'] + $membership);
    $r = Role::firstOrCreate(['tenant_id' => $t->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $u;
}

function msdSecurityAlerts(User $u): array
{
    return UserNotification::where('user_id', $u->id)->where('type', 'SECURITY')->pluck('title')->all();
}

it('B1/B2: a sign-in stores device detail and a login row with outcome, app version and masked IP; failures are recorded', function () {
    config(['security_centre.login.geo_headers' => ['country' => 'X-Geo-Country', 'city' => 'X-Geo-City']]);
    app(Laravel\Passport\ClientRepository::class)->createPersonalAccessGrantClient('Test personal', 'users');
    $f = makeMobileCustomerFixture('+237670005910');
    $user = $f['user'];
    $user->forceFill(['password' => 'Secret123'])->save();
    $h = ['X-App-Version' => '2.4.1', 'X-Device-Model' => 'Pixel 8', 'X-OS-Version' => 'Android 15', 'X-Geo-Country' => 'cm', 'X-Geo-City' => 'Douala', 'REMOTE_ADDR' => '41.202.7.9'];

    $this->withServerVariables(['REMOTE_ADDR' => '41.202.7.9'])->postJson('/api/v1/auth/mobile/password-login', ['phone_e164' => '+237670005910', 'password' => 'Wrong1234', 'device' => MSD_DEVICE], $h)->assertStatus(422);
    $this->withServerVariables(['REMOTE_ADDR' => '41.202.7.9'])->postJson('/api/v1/auth/mobile/password-login', ['phone_e164' => '+237670005910', 'password' => 'Secret123', 'device' => MSD_DEVICE], $h)->assertSuccessful();

    $device = UserDevice::where('user_id', $user->id)->firstOrFail();
    expect($device->model)->toBe('Pixel 8')->and($device->os_version)->toBe('Android 15')->and($device->app_version)->toBe('2.4.1')
        ->and($device->last_auth_method)->toBe('password')->and($device->first_seen_at)->not->toBeNull()
        ->and($device->approx_country)->toBe('CM')->and($device->approx_city)->toBe('Douala');

    Passport::actingAs($user);
    $rows = $this->getJson('/api/v1/mobile/account/devices', tenantHeaderFor($f['tenant']) + ['X-Device-Fingerprint' => 'msd-dev-1'])->assertOk()->json('data');
    expect($rows[0])->toMatchArray(['model' => 'Pixel 8', 'os_version' => 'Android 15', 'app_version' => '2.4.1', 'last_auth_method' => 'password',
        'attestation_status' => 'UNVERIFIED', 'approx_location' => 'Douala, CM', 'current' => true])
        ->and($rows[0]['first_seen_at'])->toBeString();

    $activity = collect($this->getJson('/api/v1/me/security/login-activity', tenantHeaderFor($f['tenant']))->assertOk()->json('data'));
    $ok = $activity->firstWhere('outcome', 'SUCCESS');
    $failed = $activity->firstWhere('outcome', 'FAILED');
    expect($ok)->toMatchArray(['event_type' => 'LOGIN', 'app_version' => '2.4.1', 'masked_ip' => '41.202.x.x'])
        ->and($failed)->toMatchArray(['event_type' => 'LOGIN_FAILED', 'method' => 'password']);
});

it('B3: three failed sign-ins and a new device raise security alerts deep-linked to /account/security', function () {
    app(Laravel\Passport\ClientRepository::class)->createPersonalAccessGrantClient('Test personal', 'users');
    $user = User::create(['full_name' => 'Pat', 'phone_e164' => '+237670005911', 'password' => 'Secret123', 'locale' => 'en', 'status' => 'ACTIVE']);
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/v1/auth/mobile/password-login', ['phone_e164' => '+237670005911', 'password' => 'Wrong1234', 'device' => MSD_DEVICE])->assertStatus(422);
    }
    $this->postJson('/api/v1/auth/mobile/password-login', ['phone_e164' => '+237670005911', 'password' => 'Secret123', 'device' => MSD_DEVICE])->assertSuccessful();
    $this->postJson('/api/v1/auth/mobile/password-login', ['phone_e164' => '+237670005911', 'password' => 'Secret123', 'device' => ['fingerprint' => 'msd-dev-2', 'name' => 'Other', 'platform' => 'ios']])->assertSuccessful();

    expect(msdSecurityAlerts($user))->toContain('Failed sign-in attempts', 'New device signed in')
        ->and(UserNotification::where('user_id', $user->id)->where('type', 'SECURITY')->whereIn('title', ['Failed sign-in attempts', 'New device signed in'])->pluck('path')->unique()->all())->toBe(['/account/security']);
});

it('B5: sign-out-everywhere needs a SIGN_OUT_EVERYWHERE grant, then revokes, records and alerts', function () {
    $f = makeMobileCustomerFixture('+237671115010');
    Passport::actingAs($f['user']);

    $this->postJson('/api/v1/auth/mobile/logout-all')->assertStatus(401)->assertJsonPath('code', 'STEP_UP_REQUIRED');
    $wrong = issueMobileStepUpGrant($f['user'], $f['tenant'], 'PAYMENT_REFUND_REQUEST');
    $this->postJson('/api/v1/auth/mobile/logout-all', [], stepUpHeaderFor($wrong['token']))->assertStatus(401);
    $grant = issueMobileStepUpGrant($f['user'], $f['tenant'], 'SIGN_OUT_EVERYWHERE');
    $this->postJson('/api/v1/auth/mobile/logout-all', [], stepUpHeaderFor($grant['token']))->assertOk();

    expect(DB::table('login_activities')->where('user_id', $f['user']->id)->where('event_type', 'SIGN_OUT_EVERYWHERE')->exists())->toBeTrue()
        ->and(DB::table('login_activities')->where('user_id', $f['user']->id)->where('event_type', 'STEP_UP')->where('outcome', 'FAILED')->exists())->toBeTrue()
        ->and(msdSecurityAlerts($f['user']))->toContain('Signed out everywhere');
});

it('B5: the three new purposes are accepted by step-up request', function () {
    $f = makeMobileCustomerFixture('+237671115011');
    Passport::actingAs($f['user']);
    foreach (['PAYOUT_DESTINATION_CHANGE', 'PROFILE_SECURITY_CHANGE', 'SIGN_OUT_EVERYWHERE'] as $purpose) {
        $this->postJson('/api/v1/mobile/security/step-up/request', ['purpose' => $purpose], tenantHeaderFor($f['tenant']) + ['Idempotency-Key' => (string) Str::uuid()])->assertSuccessful();
    }
    $this->postJson('/api/v1/mobile/security/step-up/request', ['purpose' => 'NOT_A_PURPOSE'], tenantHeaderFor($f['tenant']) + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('purpose');
});

it('B5: a payout destination change needs PAYOUT_DESTINATION_CHANGE; a name-only edit does not', function () {
    $a = makeMobileAgentFixture('+237680005912');
    Passport::actingAs($a['user']);
    $h = tenantHeaderFor($a['tenant']);

    $this->patchJson('/api/v1/mobile/agent/profile', ['full_name' => 'Agent Renamed'], $h)->assertOk();
    $this->patchJson('/api/v1/mobile/agent/profile', ['momo_phone_e164' => '+237670009999'], $h)->assertStatus(401)->assertJsonPath('code', 'STEP_UP_REQUIRED');
    expect($a['partner']->fresh()->compliance['momo_phone_e164'] ?? null)->toBeNull();

    $grant = issueMobileStepUpGrant($a['user'], $a['tenant'], 'PAYOUT_DESTINATION_CHANGE');
    $this->patchJson('/api/v1/mobile/agent/profile', ['momo_phone_e164' => '+237670009999'], $h + stepUpHeaderFor($grant['token']))->assertOk();
    expect($a['partner']->fresh()->compliance['momo_phone_e164'])->toBe('+237670009999')
        ->and(msdSecurityAlerts($a['user']))->toContain('Payout destination changed');
});

it('B5: changing the account e-mail needs PROFILE_SECURITY_CHANGE', function () {
    $f = makeMobileCustomerFixture('+237671115013');
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']);

    $this->patchJson('/api/v1/mobile/account/profile', ['full_name' => 'Same Mail', 'email' => $f['user']->email], $h)->assertOk();
    $this->patchJson('/api/v1/mobile/account/profile', ['full_name' => 'New Mail', 'email' => 'new@example.com'], $h)->assertStatus(401);
    $grant = issueMobileStepUpGrant($f['user'], $f['tenant'], 'PROFILE_SECURITY_CHANGE');
    $this->patchJson('/api/v1/mobile/account/profile', ['full_name' => 'New Mail', 'email' => 'new@example.com'], $h + stepUpHeaderFor($grant['token']))->assertOk()->assertJsonPath('data.email', 'new@example.com');
    expect(msdSecurityAlerts($f['user']))->toContain('Account details changed');
});

it('B6: Play Integrity without server credentials is UNVERIFIED + CONFIG_REQUIRED, never a pass', function () {
    config(['security_centre.attestation.play_integrity' => ['enabled' => false]]);
    $f = makeMobileCustomerFixture('+237671115014');
    UserDevice::create(['user_id' => $f['user']->id, 'device_fingerprint' => 'att-1', 'name' => 'P', 'platform' => 'android', 'last_seen_at' => now()]);
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']) + ['X-Device-Fingerprint' => 'att-1'];

    $nonce = $this->postJson('/api/v1/mobile/security/device-attestation/nonce', [], $h)->json('data.nonce');
    $r = $this->postJson('/api/v1/mobile/security/device-attestation/assess', ['nonce' => $nonce, 'platform' => 'ANDROID', 'provider' => 'PLAY_INTEGRITY', 'token' => 'tok'], $h)->assertCreated();
    expect($r->json('data.attestation'))->toMatchArray(['verdict' => 'UNVERIFIED', 'config_status' => 'CONFIG_REQUIRED'])
        ->and(UserDevice::where('device_fingerprint', 'att-1')->value('attestation_status'))->toBe('UNVERIFIED');
});

it('B6: a configured Play Integrity FAIL verdict marks the device, limits it and alerts the user', function () {
    config(['security_centre.attestation.play_integrity' => ['enabled' => true, 'package_name' => 'cm.opesinsure.app', 'access_token' => 'x', 'endpoint' => 'https://playintegrity.test/v1']]);
    Http::fake(['playintegrity.test/*' => Http::response(['tokenPayloadExternal' => ['requestDetails' => ['requestPackageName' => 'cm.opesinsure.app', 'nonce' => 'bad'],
        'appIntegrity' => ['appRecognitionVerdict' => 'UNRECOGNIZED_VERSION'], 'deviceIntegrity' => ['deviceRecognitionVerdict' => []]]])]);
    $f = makeMobileCustomerFixture('+237671115015');
    UserDevice::create(['user_id' => $f['user']->id, 'device_fingerprint' => 'att-2', 'name' => 'P', 'platform' => 'android', 'last_seen_at' => now()]);
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']) + ['X-Device-Fingerprint' => 'att-2'];

    $nonce = $this->postJson('/api/v1/mobile/security/device-attestation/nonce', [], $h)->json('data.nonce');
    $r = $this->postJson('/api/v1/mobile/security/device-attestation/assess', ['nonce' => $nonce, 'platform' => 'ANDROID', 'provider' => 'PLAY_INTEGRITY', 'token' => 'tok'], $h)->assertCreated();
    expect($r->json('data.action'))->toBe('LIMIT')->and($r->json('data.attestation.verdict'))->toBe('FAIL')->and($r->json('data.attestation.config_status'))->toBe('CONFIGURED')
        ->and(UserDevice::where('device_fingerprint', 'att-2')->value('attestation_status'))->toBe('FAIL')
        ->and(DB::table('login_activities')->where('user_id', $f['user']->id)->where('event_type', 'ATTESTATION_FAILED')->exists())->toBeTrue()
        ->and(msdSecurityAlerts($f['user']))->toContain('Device integrity check failed');
});

it('B4: a carrier admin reads and contains its own carrier staff only; device detail needs security.centre.read', function () {
    $t = Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'MSD '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    $a = makeMobileFinanceProposalChain($t)['carrier']->id;
    $b = makeMobileFinanceProposalChain($t)['carrier']->id;
    $admin = msdMember($t, 'CARRIER_ADMIN', ['carrier_id' => $a]);
    $staffA = msdMember($t, 'CARRIER_STAFF', ['carrier_id' => $a]);
    $staffB = msdMember($t, 'CARRIER_STAFF', ['carrier_id' => $b]);
    UserDevice::create(['user_id' => $staffA->id, 'device_fingerprint' => 's-a', 'name' => 'Phone', 'platform' => 'android', 'last_seen_at' => now()]);
    DB::table('login_activities')->insert(['id' => (string) Str::uuid(), 'user_id' => $staffA->id, 'method' => 'otp', 'new_device' => false, 'anomaly_flags' => '[]', 'occurred_at' => now()->subHour(), 'outcome' => 'SUCCESS', 'event_type' => 'LOGIN']);
    Passport::actingAs($admin);
    $h = ['X-Tenant-Id' => $t->id, 'Accept' => 'application/json'];

    $r = $this->getJson("/api/v1/partner/staff/{$staffA->id}/security", $h)->assertOk();
    expect($r->json('data'))->toMatchArray(['status' => 'ACTIVE', 'security_state' => 'NORMAL', 'active_sessions' => 0])
        ->and($r->json('data.last_sign_in_at'))->toBeString()
        ->and($r->json('data'))->not->toHaveKey('devices');
    $this->getJson("/api/v1/partner/staff/{$staffB->id}/security", $h)->assertNotFound();
    $this->postJson("/api/v1/partner/staff/{$staffB->id}/suspend-access", ['reason' => 'lost phone'], $h)->assertNotFound();

    $this->postJson("/api/v1/partner/staff/{$staffA->id}/force-reauth", [], $h)->assertOk()->assertJsonPath('data.sessions_revoked', true);
    $this->postJson("/api/v1/partner/staff/{$staffA->id}/suspend-access", ['reason' => 'lost phone'], $h)->assertOk()->assertJsonPath('data.status', 'SUSPENDED');
    expect(TenantMembership::where('user_id', $staffA->id)->value('status'))->toBe('SUSPENDED')
        ->and(msdSecurityAlerts($staffA))->toContain('Access suspended', 'Sign in again')
        ->and($this->getJson("/api/v1/partner/staff/{$staffA->id}/security", $h)->json('data.security_state'))->toBe('SUSPENDED');
    $this->postJson("/api/v1/partner/staff/{$admin->id}/force-reauth", [], $h)->assertStatus(422);

    // With security.centre.read the device and network detail appears.
    Role::where('tenant_id', $t->id)->where('code', 'CARRIER_ADMIN')->first()->forceFill(['permissions' => [...RoleCatalogue::defaultPermissions('CARRIER_ADMIN'), 'security.centre.read']])->save();
    $detail = $this->getJson("/api/v1/partner/staff/{$staffA->id}/security", $h)->assertOk()->json('data');
    expect($detail['devices'][0]['name'])->toBe('Phone')->and($detail)->toHaveKey('recent_activity');
});

it('B4: staff without staff.security.* are refused', function () {
    $t = Tenant::create(['type' => 'BROKER', 'legal_name' => 'MSD B '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    $staff = msdMember($t, 'BROKER_STAFF');
    $other = msdMember($t, 'BROKER_STAFF');
    Passport::actingAs($staff);
    $this->getJson("/api/v1/partner/staff/{$other->id}/security", ['X-Tenant-Id' => $t->id])->assertForbidden();
    $this->postJson("/api/v1/partner/staff/{$other->id}/force-reauth", [], ['X-Tenant-Id' => $t->id])->assertForbidden();

    $admin = msdMember($t, 'BROKER_ADMIN');
    Passport::actingAs($admin);
    $this->getJson("/api/v1/partner/staff/{$other->id}/security", ['X-Tenant-Id' => $t->id])->assertOk()->assertJsonPath('data.role_code', 'BROKER_STAFF');
});

it('B6: a sideloaded build (not Play-recognised) passes on device integrity; a wrong signing certificate fails', function () {
    $digest = 'good-digest';
    config(['security_centre.attestation.play_integrity' => ['enabled' => true, 'package_name' => 'com.opesware.opesinsure', 'access_token' => 'x', 'endpoint' => 'https://playintegrity.test/v1', 'certificate_sha256' => [$digest]]]);
    $f = makeMobileCustomerFixture('+237671115016');
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']);
    $state = (object) ['nonce' => '', 'cert' => ''];
    Http::fake(['playintegrity.test/*' => fn () => Http::response(['tokenPayloadExternal' => [
        'requestDetails' => ['requestPackageName' => 'com.opesware.opesinsure', 'nonce' => rtrim(strtr(base64_encode($state->nonce), '+/', '-_'), '=')],
        'appIntegrity' => ['appRecognitionVerdict' => 'UNRECOGNIZED_VERSION', 'certificateSha256Digest' => [$state->cert]],
        'deviceIntegrity' => ['deviceRecognitionVerdict' => ['MEETS_DEVICE_INTEGRITY']]]])]);
    $assess = function (string $cert) use ($h, $state) {
        $nonce = $this->postJson('/api/v1/mobile/security/device-attestation/nonce', [], $h)->json('data.nonce');
        $state->nonce = $nonce;
        $state->cert = $cert;

        return $this->postJson('/api/v1/mobile/security/device-attestation/assess', ['nonce' => $nonce, 'platform' => 'ANDROID', 'provider' => 'PLAY_INTEGRITY', 'token' => 'tok'], $h)->assertCreated()->json('data');
    };

    $ok = $assess($digest);
    expect($ok['attestation']['verdict'])->toBe('PASS')->and($ok['attestation']['reasons'])->toContain('APP_NOT_RECOGNIZED')->and($ok['action'])->toBe('ALLOW');
    $bad = $assess('other-digest');
    expect($bad['attestation']['verdict'])->toBe('FAIL')->and($bad['attestation']['reasons'])->toContain('CERTIFICATE_MISMATCH')->and($bad['action'])->toBe('LIMIT');
});

it('push-tokens accepts an Expo token with a platform', function () {
    $f = makeMobileCustomerFixture('+237671115017');
    Passport::actingAs($f['user']);
    $this->postJson('/api/v1/mobile/account/push-tokens', ['token' => 'ExponentPushToken[abc123]', 'platform' => 'android'], tenantHeaderFor($f['tenant']))
        ->assertCreated()->assertJsonPath('data.provider', 'expo')->assertJsonPath('data.platform', 'android');
    expect(in_array('PUSH_REGISTRATION_FAILED', config('mobile_runtime.telemetry.events'), true))->toBeTrue();
});
