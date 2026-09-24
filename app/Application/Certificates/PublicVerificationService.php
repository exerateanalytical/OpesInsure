<?php

declare(strict_types=1);

namespace App\Application\Certificates;

use App\Models\Policy;
use App\Models\PolicyCertificate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Public certificate verification with minimal disclosure: validity, insurer,
 * product class/name and the coverage window — never the holder's name,
 * premium or contact data.
 *
 * QR_PAGE (GET /verify?ref=SERIAL&t=TOKEN, the URL printed in the
 * certificate QR) requires the verification token embedded in the QR: a
 * missing or wrong token gets exactly the same generic "not_found" answer as
 * an unknown reference, so serial numbers cannot be enumerated.
 *
 * Every lookup — found or not — is logged in public_verification_lookups
 * (hashes only); a found certificate also gets a certificate_verification_events row.
 */
final class PublicVerificationService
{
    /** @return array<string, mixed> */
    public function lookup(string $reference, ?string $token, string $channel, string $fingerprint): array
    {
        $reference = trim($reference);
        $certificate = $reference !== '' ? PolicyCertificate::with('policy.carrier.party', 'policy.proposal.offer.product')->where('serial_number', $reference)->first() : null;
        $policy = null;

        if ($channel === 'QR_PAGE') {
            $tokenOk = $certificate && $token !== null && $token !== '' && hash_equals($certificate->verification_token_hash, hash('sha256', $token));
            $policy = $tokenOk ? $certificate->policy : null;
            if (! $tokenOk) {
                $certificate = null;
            }
        } else {
            $policy = $certificate?->policy
                ?? ($reference !== '' ? Policy::where('certificate_number', $reference)->first() : null)
                ?? ($reference !== '' ? Policy::where('policy_number', $reference)->first() : null);
        }

        $result = $policy ? self::resultFor($policy, $certificate) : 'not_found';

        DB::table('public_verification_lookups')->insert([
            'id' => (string) Str::uuid(), 'channel' => $channel, 'reference_hash' => hash('sha256', mb_strtoupper($reference)),
            'policy_certificate_id' => $certificate?->id, 'result' => $result, 'token_presented' => $token !== null && $token !== '',
            'request_fingerprint_hash' => hash('sha256', $fingerprint), 'occurred_at' => now(),
        ]);
        if ($certificate) {
            DB::table('certificate_verification_events')->insert([
                'id' => (string) Str::uuid(), 'policy_certificate_id' => $certificate->id,
                'result' => $result === 'valid' ? 'VERIFIED' : 'REJECTED',
                'request_fingerprint_hash' => hash('sha256', $fingerprint), 'occurred_at' => now(),
            ]);
        }

        if (! $policy) {
            return ['result' => 'not_found', 'reference' => $reference, 'carrier_name' => null, 'product_class' => null, 'product_name' => null, 'policy_status' => null, 'coverage_starts_at' => null, 'coverage_ends_at' => null, 'checked_at' => now()->toIso8601String()];
        }

        $policy->loadMissing(['carrier.party', 'proposal.offer.product']);
        $product = $policy->proposal?->offer?->product;

        return [
            'result' => $result,
            'reference' => $reference,
            'carrier_name' => $policy->carrier?->party?->display_name,
            'product_class' => $product?->line_code,
            'product_name' => $product?->name,
            'policy_status' => $policy->status,
            'coverage_starts_at' => $policy->coverage_starts_at?->toIso8601String(),
            'coverage_ends_at' => $policy->coverage_ends_at?->toIso8601String(),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /** valid | expired | not_yet_active | suspended | cancelled | revoked | invalid */
    public static function resultFor(Policy $policy, ?PolicyCertificate $certificate): string
    {
        if ($certificate && $certificate->status !== 'VALID') {
            return 'revoked';
        }

        return match (true) {
            $policy->status === 'SUSPENDED' => 'suspended',
            in_array($policy->status, ['CANCELLED', 'VOID', 'LAPSED'], true) => 'cancelled',
            $policy->status === 'EXPIRED' || (bool) $policy->coverage_ends_at?->isPast() => 'expired',
            (bool) $policy->coverage_starts_at?->isFuture() => 'not_yet_active',
            in_array($policy->status, ['ACTIVE', 'EXPIRING', 'ENDORSEMENT_PENDING', 'CANCELLATION_PENDING'], true) => 'valid',
            default => 'invalid',
        };
    }
}
