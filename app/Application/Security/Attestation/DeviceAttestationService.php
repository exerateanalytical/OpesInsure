<?php

declare(strict_types=1);

namespace App\Application\Security\Attestation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Routes an attestation to the provider's verifier and records the verdict
 * (device_integrity_attestations). No token / managed runtime / unknown provider
 * → UNVERIFIED. The token itself is never stored; only a hash of the nonce.
 */
final class DeviceAttestationService
{
    /** @param array<string, DeviceAttestationVerifier> $verifiers keyed by provider code */
    public function __construct(private readonly array $verifiers) {}

    public function verify(string $userId, string $platform, string $provider, ?string $token, string $nonce): AttestationVerdict
    {
        $verifier = $this->verifiers[$provider] ?? null;
        $verdict = match (true) {
            $verifier === null => AttestationVerdict::unverified('ATTESTATION_UNAVAILABLE'),
            $token === null || $token === '' => AttestationVerdict::unverified('NO_TOKEN'),
            default => $verifier->verify($platform, $token, $nonce),
        };
        DB::table('device_integrity_attestations')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $userId, 'platform' => $platform, 'provider' => $provider, 'verdict' => $verdict->verdict,
            'verifier' => $verifier?->name() ?? 'none', 'reasons' => json_encode($verdict->reasons), 'nonce_hash' => hash('sha256', $nonce), 'created_at' => now(),
        ]);

        return $verdict;
    }
}
