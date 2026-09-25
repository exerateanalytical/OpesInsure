<?php

declare(strict_types=1);

namespace App\Application\Security\Attestation;

/**
 * Apple App Attest. Full verification (CBOR attestation object, Apple App
 * Attestation Root CA chain, nonce/clientDataHash, RP-ID = SHA256(teamId.bundleId),
 * counter tracking) is not implemented in this batch, so this adapter always
 * returns UNVERIFIED — NOT_CONFIGURED when switched off, VERIFIER_NOT_IMPLEMENTED
 * when switched on. It never returns PASS. Bind a real implementation of
 * DeviceAttestationVerifier in the container to replace it.
 */
final class AppAttestVerifier implements DeviceAttestationVerifier
{
    public function name(): string
    {
        return 'app_attest';
    }

    public function verify(string $platform, string $token, string $nonce): AttestationVerdict
    {
        $c = config('security_centre.attestation.app_attest', []);
        if (empty($c['enabled']) || empty($c['team_id']) || empty($c['bundle_id'])) {
            return AttestationVerdict::unverified('NOT_CONFIGURED');
        }

        return AttestationVerdict::unverified('VERIFIER_NOT_IMPLEMENTED');
    }
}
