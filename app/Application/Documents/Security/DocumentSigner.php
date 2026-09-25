<?php

declare(strict_types=1);

namespace App\Application\Documents\Security;

use App\Application\Shared\CanonicalJson;

/**
 * Platform document signature (crypto spec §2 C2/C3, §5, §7). Ed25519 (libsodium) detached signature over
 * the canonical signing payload: document uuid + number, final file SHA-256, content hash, snapshot hash,
 * template hash, issue instant. The signature is stored on the registry record; the PDF bytes are never
 * altered after hashing (§4 step 16 not needed: signing does not change the bytes).
 *
 * Key custody (§7.1): the secret key is loaded from the environment or a key file outside the repository;
 * it is never committed, never stored in the database. A key bound to another environment is refused
 * (§7.2). Without a key every signature control is CONFIG_REQUIRED — nothing is faked.
 * NOT implemented (CONFIG_REQUIRED, see gap audit): PAdES-embedded PDF signature, X.509 certificate chain,
 * RFC 3161 trusted timestamp, HSM/KMS custody.
 */
final class DocumentSigner
{
    public function __construct(private CanonicalJson $json) {}

    public function configured(): bool
    {
        return $this->configurationIssue() === null;
    }

    /** CONFIG_REQUIRED reason when not configured, else null. */
    public function configurationIssue(): ?string
    {
        if (! function_exists('sodium_crypto_sign_detached')) {
            return 'libsodium extension missing';
        }
        $raw = $this->rawSecret();
        if ($raw === null) {
            return 'DOCUMENT_SIGNING_KEY / DOCUMENT_SIGNING_KEY_PATH not set';
        }
        $env = config('document_security.signing.key_environment');
        if (! $env || $env !== app()->environment()) {
            return 'signing key environment ('.($env ?: 'unset').') does not match '.app()->environment();
        }
        if (! config('document_security.signing.key_id')) {
            return 'DOCUMENT_SIGNING_KEY_ID not set';
        }
        if (strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return 'signing key is not a 64-byte Ed25519 secret key';
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    public function payloadBytes(array $payload): string
    {
        return $this->json->encode($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> signature record (stored in documents.signature)
     */
    public function sign(array $payload): array
    {
        $issue = $this->configurationIssue();
        if ($issue !== null) {
            return ['status' => 'CONFIG_REQUIRED', 'reason' => $issue, 'algorithm' => null, 'key_id' => null, 'payload' => $payload,
                'pades' => 'CONFIG_REQUIRED', 'timestamp' => 'CONFIG_REQUIRED'];
        }
        $secret = (string) $this->secretKey();
        $public = sodium_crypto_sign_publickey_from_secretkey($secret);

        return [
            'status' => 'SIGNED', 'algorithm' => 'ED25519', 'key_id' => (string) config('document_security.signing.key_id'),
            'public_key_fingerprint' => hash('sha256', $public), 'payload' => $payload,
            'value' => base64_encode(sodium_crypto_sign_detached($this->payloadBytes($payload), $secret)),
            'signed_at' => now()->utc()->toIso8601String(),
            'pades' => 'CONFIG_REQUIRED', // PAdES embedding needs a PAdES-capable PDF library
            'timestamp' => config('document_security.signing.tsa_url') ? 'NOT_IMPLEMENTED' : 'CONFIG_REQUIRED',
        ];
    }

    /**
     * Verifies a stored signature against its payload with the current or a retired public key.
     *
     * @param  array<string, mixed>|null  $signature
     */
    public function verify(?array $signature): string
    {
        if (! $signature || ($signature['status'] ?? null) !== 'SIGNED') {
            return (string) ($signature['status'] ?? 'NOT_SIGNED');
        }
        $public = $this->publicKeyFor((string) ($signature['key_id'] ?? ''));
        if ($public === null) {
            return 'UNVERIFIABLE';
        }
        if (hash('sha256', $public) !== ($signature['public_key_fingerprint'] ?? null)) {
            return 'SIGNATURE_INVALID';
        }
        $ok = sodium_crypto_sign_verify_detached(base64_decode((string) $signature['value'], true) ?: '', $this->payloadBytes((array) $signature['payload']), $public);

        return $ok ? 'VALID' : 'SIGNATURE_INVALID';
    }

    public function publicKeyFor(string $keyId): ?string
    {
        if ($keyId !== '' && $keyId === config('document_security.signing.key_id') && ($secret = $this->secretKey())) {
            return sodium_crypto_sign_publickey_from_secretkey($secret);
        }
        $retired = (array) config('document_security.signing.public_keys', []);
        $b64 = $retired[$keyId] ?? null;
        $raw = is_string($b64) ? base64_decode($b64, true) : false;

        return $raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $raw : null;
    }

    /** Public verification material (key id => base64 public key) for offline verifiers. */
    public function publicKeys(): array
    {
        $keys = array_filter((array) config('document_security.signing.public_keys', []), 'is_string');
        if ($this->configurationIssue() === null && ($secret = $this->secretKey())) {
            $keys[(string) config('document_security.signing.key_id')] = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
        }

        return $keys;
    }

    private function secretKey(): ?string
    {
        $raw = $this->rawSecret();

        return $raw !== null && strlen($raw) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ? $raw : null;
    }

    private function rawSecret(): ?string
    {
        $b64 = config('document_security.signing.private_key');
        $path = config('document_security.signing.private_key_path');
        if (! $b64 && $path && is_file($path)) {
            $b64 = trim((string) file_get_contents($path));
        }
        if (! $b64) {
            return null;
        }
        $raw = base64_decode((string) $b64, true);

        return $raw === false ? null : $raw;
    }
}
