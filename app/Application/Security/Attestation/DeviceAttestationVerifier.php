<?php

declare(strict_types=1);

namespace App\Application\Security\Attestation;

/**
 * REQ-SEC-005 / REQ-MOB-007: verifies a platform attestation token (Play
 * Integrity / App Attest) bound to a server nonce. Implementations MUST
 * return UNVERIFIED — never PASS — when they are not configured or cannot
 * complete verification.
 */
interface DeviceAttestationVerifier
{
    public function name(): string;

    public function verify(string $platform, string $token, string $nonce): AttestationVerdict;
}
