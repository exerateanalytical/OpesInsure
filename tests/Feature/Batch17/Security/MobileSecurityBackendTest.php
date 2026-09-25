<?php

declare(strict_types=1);

use App\Application\Security\Attestation\AttestationVerdict;
use App\Application\Security\Attestation\DeviceAttestationService;
use App\Application\Security\Attestation\DeviceAttestationVerifier;
use App\Application\Security\Crash\CrashReportIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

function b7User(): array
{
    $t = makeAuthTestTenant();

    return [makeAuthTestUser($t, []), tenantHeader($t)];
}

function b7Assess($test, array $u, array $body): array
{
    Passport::actingAs($u[0]);
    $nonce = $test->postJson('/api/v1/mobile/security/device-attestation/nonce', [], $u[1])->assertCreated()->json('data.nonce');

    return $test->postJson('/api/v1/mobile/security/device-attestation/assess', ['nonce' => $nonce, ...$body], $u[1])->assertCreated()->json('data');
}

it('REQ-SEC-005 unconfigured attestation verifiers yield UNVERIFIED, never PASS', function () {
    $user = b7User();
    foreach (['PLAY_INTEGRITY' => 'ANDROID', 'APP_ATTEST' => 'IOS', 'UNAVAILABLE_MANAGED_RUNTIME' => 'ANDROID'] as $provider => $platform) {
        $d = b7Assess($this, $user, ['platform' => $platform, 'provider' => $provider, 'token' => 'opaque-token']);
        expect($d['attestation']['verdict'])->toBe('UNVERIFIED')->and($d['action'])->toBe('ALLOW');
    }
    config(['security_centre.attestation.app_attest' => ['enabled' => true, 'team_id' => 'T', 'bundle_id' => 'b']]);
    expect(b7Assess($this, $user, ['platform' => 'IOS', 'provider' => 'APP_ATTEST', 'token' => 't'])['attestation']['reasons'])->toBe(['VERIFIER_NOT_IMPLEMENTED']);
    expect(DB::table('device_integrity_attestations')->where('verdict', 'PASS')->exists())->toBeFalse();
    expect(DB::table('device_integrity_attestations')->count())->toBe(4);
});

it('REQ-SEC-005 Play Integrity (configured) checks nonce, package, app and device verdicts; FAIL limits the device', function () {
    config(['security_centre.attestation.play_integrity' => ['enabled' => true, 'package_name' => 'cm.opes.app', 'access_token' => 'tok', 'endpoint' => 'https://pi.test/v1']]);
    $user = b7User();
    $good = null;
    Http::fake(['pi.test/*' => function ($req) use (&$good) {
        if ($good === '__down__') {
            return Http::response([], 500);
        }

        return Http::response(['tokenPayloadExternal' => [
            'requestDetails' => ['nonce' => $good, 'requestPackageName' => 'cm.opes.app'],
            'appIntegrity' => ['appRecognitionVerdict' => 'PLAY_RECOGNIZED'],
            'deviceIntegrity' => ['deviceRecognitionVerdict' => ['MEETS_DEVICE_INTEGRITY']],
        ]]);
    }]);
    Passport::actingAs($user[0]);
    $nonce = $this->postJson('/api/v1/mobile/security/device-attestation/nonce', [], $user[1])->json('data.nonce');
    $good = $nonce;
    $d = $this->postJson('/api/v1/mobile/security/device-attestation/assess', ['nonce' => $nonce, 'platform' => 'ANDROID', 'provider' => 'PLAY_INTEGRITY', 'token' => 't'], $user[1])->assertCreated()->json('data');
    expect($d['attestation']['verdict'])->toBe('PASS')->and($d['action'])->toBe('ALLOW');

    $good = 'someone-elses-nonce';
    $d = b7Assess($this, $user, ['platform' => 'ANDROID', 'provider' => 'PLAY_INTEGRITY', 'token' => 't']);
    expect($d['attestation']['verdict'])->toBe('FAIL')->and($d['attestation']['reasons'])->toContain('NONCE_MISMATCH')->and($d['action'])->toBe('LIMIT');

    $good = '__down__';
    expect(b7Assess($this, $user, ['platform' => 'ANDROID', 'provider' => 'PLAY_INTEGRITY', 'token' => 't'])['attestation']['verdict'])->toBe('UNVERIFIED');
});

it('REQ-SEC-005 verifiers are swappable behind the interface', function () {
    $fake = new class implements DeviceAttestationVerifier
    {
        public function name(): string
        {
            return 'fake';
        }

        public function verify(string $platform, string $token, string $nonce): AttestationVerdict
        {
            return new AttestationVerdict(AttestationVerdict::FAIL, ['TAMPERED']);
        }
    };
    app()->instance(DeviceAttestationService::class, new DeviceAttestationService(['APP_ATTEST' => $fake]));
    $d = b7Assess($this, b7User(), ['platform' => 'IOS', 'provider' => 'APP_ATTEST', 'token' => 't']);
    expect($d['action'])->toBe('LIMIT')->and($d['reasons'])->toContain('ATTESTATION_FAILED');
});

it('REQ-MOB-007 serves configurable assetlinks.json and apple-app-site-association (empty when unconfigured)', function () {
    $this->get('/.well-known/assetlinks.json')->assertOk()->assertExactJson([]);
    $this->get('/.well-known/apple-app-site-association')->assertOk()->assertJsonPath('applinks.details', []);

    config(['security_centre.app_links' => [
        'android' => ['package_name' => 'cm.opes.app', 'sha256_cert_fingerprints' => ['AA:BB']],
        'ios' => ['app_ids' => ['TEAM1.cm.opes.app'], 'paths' => ['/app/*', '/verify/*']],
    ]]);
    $this->get('/.well-known/assetlinks.json')->assertOk()->assertJsonPath('0.target.package_name', 'cm.opes.app')
        ->assertJsonPath('0.target.sha256_cert_fingerprints', ['AA:BB'])->assertJsonPath('0.relation', ['delegate_permission/common.handle_all_urls']);
    $this->get('/.well-known/apple-app-site-association')->assertOk()->assertJsonPath('applinks.details.0.appIDs', ['TEAM1.cm.opes.app'])
        ->assertJsonPath('applinks.details.0.components.1', ['/' => '/verify/*']);
});

it('REQ-MOB-007 ingests crash reports anonymously, scrubs PII, groups repeats, rejects extra fields', function () {
    $body = ['platform' => 'ANDROID', 'app_version' => '1.4.0', 'error_type' => 'TypeError',
        'message' => 'Failed for amina@example.cm phone +237 670 12 34 56 token=eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abc policy 5c1f2e0a-1b2c-4d3e-8f90-123456789abc',
        'stack' => "at render (/data/user/0/cm.opes.app/files/index.js:10:5)\nat https://api.opes.cm/v1/claims?party=123&email=x@y.cm"];
    $a = $this->postJson('/api/v1/mobile/runtime/crash-reports', $body)->assertCreated()->json('data');
    $b = $this->postJson('/api/v1/mobile/runtime/crash-reports', $body)->assertCreated()->json('data');
    expect($a['id'])->toBe($b['id'])->and($b['occurrences'])->toBe(2);

    $row = DB::table('mobile_crash_reports')->find($a['id']);
    foreach (['amina@example.cm', '670 12 34 56', 'eyJhbGci', '5c1f2e0a', 'party=123', 'x@y.cm'] as $pii) {
        expect($row->message.$row->stack)->not->toContain($pii);
    }
    expect(Schema::getColumnListing('mobile_crash_reports'))->not->toContain('user_id')->not->toContain('ip_hash');

    $this->postJson('/api/v1/mobile/runtime/crash-reports', [...$body, 'user_email' => 'a@b.cm'])->assertStatus(422);
    expect(CrashReportIngestor::scrub('password: hunter2'))->toBe('[REDACTED_SECRET]');

    Passport::actingAs(makeAuthTestUser($t = makeAuthTestTenant(), ['security.centre.read']));
    $this->getJson('/api/v1/security-centre/crash-reports', tenantHeader($t))->assertOk()->assertJsonPath('data.0.occurrences', 2);
});
