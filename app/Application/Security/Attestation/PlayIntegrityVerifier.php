<?php

declare(strict_types=1);

namespace App\Application\Security\Attestation;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Play Integrity: server-side decodeIntegrityToken. Only runs when
 * security_centre.attestation.play_integrity is enabled AND has a package name
 * and access token; otherwise UNVERIFIED (NOT_CONFIGURED). Any transport or
 * decoding error is UNVERIFIED, never PASS.
 */
final class PlayIntegrityVerifier implements DeviceAttestationVerifier
{
    public function name(): string
    {
        return 'play_integrity';
    }

    public function verify(string $platform, string $token, string $nonce): AttestationVerdict
    {
        $c = config('security_centre.attestation.play_integrity', []);
        if (empty($c['enabled']) || empty($c['package_name']) || empty($c['access_token'])) {
            return AttestationVerdict::unverified('NOT_CONFIGURED');
        }
        try {
            $res = Http::withToken($c['access_token'])->timeout(8)
                ->post(rtrim((string) $c['endpoint'], '/')."/{$c['package_name']}:decodeIntegrityToken", ['integrity_token' => $token]);
            if (! $res->successful()) {
                return AttestationVerdict::unverified('PROVIDER_ERROR');
            }
            $p = $res->json('tokenPayloadExternal') ?? [];
        } catch (Throwable) {
            return AttestationVerdict::unverified('PROVIDER_UNREACHABLE');
        }

        $reasons = [];
        $expectedNonce = rtrim(strtr(base64_encode($nonce), '+/', '-_'), '=');
        $got = rtrim((string) ($p['requestDetails']['nonce'] ?? $p['requestDetails']['requestHash'] ?? ''), '=');
        if ($got === '' || ! (hash_equals($expectedNonce, $got) || hash_equals($nonce, $got))) {
            $reasons[] = 'NONCE_MISMATCH';
        }
        if (($p['requestDetails']['requestPackageName'] ?? null) !== $c['package_name']) {
            $reasons[] = 'PACKAGE_MISMATCH';
        }
        if (($p['appIntegrity']['appRecognitionVerdict'] ?? null) !== 'PLAY_RECOGNIZED') {
            $reasons[] = 'APP_NOT_RECOGNIZED';
        }
        if (! in_array('MEETS_DEVICE_INTEGRITY', (array) ($p['deviceIntegrity']['deviceRecognitionVerdict'] ?? []), true)) {
            $reasons[] = 'DEVICE_INTEGRITY_NOT_MET';
        }

        return $reasons === [] ? new AttestationVerdict(AttestationVerdict::PASS, ['PLAY_INTEGRITY_OK']) : new AttestationVerdict(AttestationVerdict::FAIL, $reasons);
    }
}
