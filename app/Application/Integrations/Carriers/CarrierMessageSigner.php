<?php

declare(strict_types=1);

namespace App\Application\Integrations\Carriers;

use App\Application\Claims\Execution\ClaimCarrierSignatureVerifier;
use Illuminate\Support\Facades\{Crypt,DB};
use RuntimeException;

/**
 * REQ-API-007 — outbound signing for all carrier traffic, generalising the Batch 11 claim scheme
 * (ClaimCarrierSignatureVerifier): same per-carrier keys (claim_carrier_signing_keys, encrypted at rest)
 * and the same signature = hex(hmac_sha256("{timestamp}.{raw body}", secret)), so a carrier verifies our
 * requests exactly as we verify theirs (same ±TOLERANCE_SECONDS replay window).
 */
final class CarrierMessageSigner
{
    public const KEY_HEADER = 'X-OpesInsure-Key-Id';

    public const TIMESTAMP_HEADER = 'X-OpesInsure-Timestamp';

    public const SIGNATURE_HEADER = 'X-OpesInsure-Signature';

    public const TOLERANCE_SECONDS = ClaimCarrierSignatureVerifier::TOLERANCE_SECONDS;

    /** @return array<string,string> */
    public function sign(string $carrierId, string $keyId, string $body, ?int $timestamp = null): array
    {
        $key = DB::table('claim_carrier_signing_keys')->where(['carrier_id' => $carrierId, 'key_id' => $keyId, 'status' => 'ACTIVE'])->first();
        if ($key === null) {
            throw new RuntimeException("No ACTIVE signing key {$keyId} for carrier {$carrierId}.");
        }
        $timestamp ??= time();

        return [
            self::KEY_HEADER => $keyId,
            self::TIMESTAMP_HEADER => (string) $timestamp,
            self::SIGNATURE_HEADER => hash_hmac('sha256', $timestamp.'.'.$body, Crypt::decryptString($key->secret_encrypted)),
        ];
    }
}
