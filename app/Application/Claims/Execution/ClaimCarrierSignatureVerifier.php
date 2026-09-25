<?php

declare(strict_types=1);

namespace App\Application\Claims\Execution;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CLM-011 — HMAC-SHA256 verification of API_SYNCHRONIZED carrier messages: signature =
 * hex(hmac_sha256("{timestamp}.{raw body}", secret)) with a per-carrier key (encrypted at rest), within
 * a replay window, like the payment provider webhooks.
 */
final class ClaimCarrierSignatureVerifier
{
    public const TOLERANCE_SECONDS = 300;

    public function verify(string $carrierId, string $keyId, int $timestamp, string $body, string $signature): void
    {
        $key = DB::table('claim_carrier_signing_keys')->where(['carrier_id' => $carrierId, 'key_id' => $keyId, 'status' => 'ACTIVE'])->first();
        $ok = $key !== null && $timestamp > 0 && abs(time() - $timestamp) <= self::TOLERANCE_SECONDS
            && hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, Crypt::decryptString($key->secret_encrypted)), strtolower($signature));
        if (! $ok) {
            throw ValidationException::withMessages(['signature' => __('batch11_claims_execution.signature_invalid')]);
        }
    }

    /** Registers (or rotates in) a signing key for a carrier; the plain secret is returned once. @return array{key_id:string,secret:string} */
    public function register(string $carrierId, User $actor, ?string $keyId = null): array
    {
        $keyId ??= 'ck_'.Str::lower(Str::random(12));
        $secret = Str::random(48);
        DB::table('claim_carrier_signing_keys')->insert(['id' => (string) Str::uuid(), 'carrier_id' => $carrierId, 'key_id' => $keyId,
            'secret_encrypted' => Crypt::encryptString($secret), 'status' => 'ACTIVE', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);

        return ['key_id' => $keyId, 'secret' => $secret];
    }

    public function revoke(string $carrierId, string $keyId): void
    {
        DB::table('claim_carrier_signing_keys')->where(['carrier_id' => $carrierId, 'key_id' => $keyId])->update(['status' => 'REVOKED', 'revoked_at' => now(), 'updated_at' => now()]);
    }
}
