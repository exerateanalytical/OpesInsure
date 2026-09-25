<?php

declare(strict_types=1);

namespace App\Application\Documents\Verification;

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
 *
 * REQ-DUP-015: this is THE public verification service. Every entry point is
 * an alias onto it: POST public/verify (canonical API), POST
 * public/insurance/verify (reference only), POST public/certificates/verify
 * (serial + token, via verifyCertificate) and GET /verify (web QR page).
 * Every answer carries the canonical WF-082 `status` (VALID | EXPIRED |
 * REVOKED | REPLACED | NOT_FOUND, plus NOT_YET_ACTIVE) next to the detailed
 * legacy `result` vocabulary.
 *
 * Not final: App\Application\Certificates\PublicVerificationService is a
 * deprecated alias subclass kept for existing type-hints.
 */
class PublicVerificationService
{
    public const STATUSES = ['VALID', 'EXPIRED', 'REVOKED', 'REPLACED', 'NOT_FOUND', 'NOT_YET_ACTIVE'];

    /** @return array<string, mixed> */
    public function lookup(string $reference, ?string $token, string $channel, string $fingerprint): array
    {
        $out = $this->resolve($reference, $token, $channel, $fingerprint);
        $out['status'] = self::canonicalStatus((string) $out['result']);

        return $out;
    }

    /** WF-082 canonical status for a detailed result. */
    public static function canonicalStatus(string $result): string
    {
        return match ($result) {
            'valid' => 'VALID',
            'expired' => 'EXPIRED',
            'replaced', 'superseded' => 'REPLACED',
            'revoked', 'cancelled', 'suspended', 'invalid' => 'REVOKED',
            'not_yet_active' => 'NOT_YET_ACTIVE',
            default => 'NOT_FOUND',
        };
    }

    /**
     * Serial + token verification (POST public/certificates/verify): engine
     * certificate documents first, then the legacy policy_certificates history.
     * Throws the generic "not verified" error unless the certificate is valid
     * and the policy in force.
     *
     * @return array{serial_number: string, policy: Policy, issued_at: mixed, document_hash: string, document_id: ?string}
     */
    public function verifyCertificate(string $serial, string $token, string $fingerprint): array
    {
        $doc = \App\Models\Document::whereRaw("provenance->>'certificate_serial' = ?", [$serial])->whereRaw("jsonb_exists(provenance, 'verification_token_hash')")->latest('created_at')->first();
        if ($doc) {
            $policy = Policy::find($doc->policy_id);
            $valid = hash_equals((string) $doc->provenance['verification_token_hash'], hash('sha256', $token))
                && in_array(\App\Application\Documents\Engine\DocumentEngine::effectiveStatus($doc), ['VALID', 'ISSUED'], true)
                && $policy && self::inForce($policy);
            DB::table('public_verification_lookups')->insert(['id' => (string) Str::uuid(), 'channel' => 'API', 'reference_hash' => hash('sha256', mb_strtoupper($serial)),
                'document_id' => $doc->id, 'result' => $valid ? 'valid' : 'not_found', 'token_presented' => true, 'request_fingerprint_hash' => hash('sha256', $fingerprint), 'occurred_at' => now()]);
            if (! $valid) {
                throw \Illuminate\Validation\ValidationException::withMessages(['certificate' => __('wave5.certificate_not_verified')]);
            }

            return ['serial_number' => $serial, 'policy' => $policy, 'issued_at' => $doc->issued_at, 'document_hash' => $doc->sha256, 'document_id' => $doc->id];
        }

        $c = PolicyCertificate::with('policy')->where('serial_number', $serial)->first();
        $valid = $c && hash_equals($c->verification_token_hash, hash('sha256', $token)) && $c->status === 'VALID' && self::inForce($c->policy);
        if ($c) {
            DB::table('certificate_verification_events')->insert(['id' => (string) Str::uuid(), 'policy_certificate_id' => $c->id, 'result' => $valid ? 'VERIFIED' : 'REJECTED',
                'request_fingerprint_hash' => hash('sha256', $fingerprint), 'occurred_at' => now()]);
        }
        if (! $valid) {
            throw \Illuminate\Validation\ValidationException::withMessages(['certificate' => __('wave5.certificate_not_verified')]);
        }

        return ['serial_number' => $c->serial_number, 'policy' => $c->policy, 'issued_at' => $c->issued_at, 'document_hash' => $c->document_hash, 'document_id' => null];
    }

    private static function inForce(Policy $p): bool
    {
        return in_array($p->status, ['ACTIVE', 'EXPIRING'], true) && $p->coverage_starts_at && $p->coverage_ends_at && ! $p->coverage_starts_at->isFuture() && $p->coverage_ends_at->isFuture();
    }

    /** @return array<string, mixed> */
    private function resolve(string $reference, ?string $token, string $channel, string $fingerprint): array
    {
        $reference = trim($reference);
        // Document engine: any issued document verifies by its verification code (the code is the secret).
        if ($reference !== '' && ($document = \App\Models\Document::where('verification_code', mb_strtoupper($reference))->first())) {
            return $this->documentResult($document, $reference, $channel, $fingerprint, $token);
        }
        // Engine-issued certificate by serial: QR_PAGE needs the QR token; the API (reference-only) does not.
        if ($reference !== '' && ($document = \App\Models\Document::whereRaw("provenance->>'certificate_serial' = ?", [$reference])->latest('created_at')->first())) {
            $hash = (string) ($document->provenance['verification_token_hash'] ?? '');
            $tokenOk = $token !== null && $token !== '' && $hash !== '' && hash_equals($hash, hash('sha256', $token));
            if ($channel !== 'QR_PAGE' || $tokenOk) {
                return $this->documentResult($document, $reference, $channel, $fingerprint, $token);
            }
        }
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

    /**
     * Minimal disclosure for a document: number, type, issuer, issue date,
     * policy reference, status (incl. SUPERSEDED / REPLACED / REVOKED /
     * EXPIRED / CANCELLED), hash, and the successor number when replaced.
     *
     * @return array<string, mixed>
     */
    private function documentResult(\App\Models\Document $d, string $reference, string $channel, string $fingerprint, ?string $token): array
    {
        $status = \App\Application\Documents\Engine\DocumentEngine::effectiveStatus($d);
        $policy = $d->policy_id ? Policy::with(['carrier.party', 'proposal.offer.product'])->find($d->policy_id) : null;
        $result = match ($status) {
            'SUPERSEDED' => 'superseded',
            'REPLACED' => 'replaced',
            'REVOKED' => 'revoked',
            'EXPIRED' => 'expired',
            'CANCELLED' => 'cancelled',
            'DRAFT', 'GENERATED', 'PENDING_SIGNATURE' => 'not_yet_active',
            default => $policy && $d->security_level === 'PUBLIC_VERIFIABLE' ? self::resultFor($policy, null) : 'valid',
        };
        DB::table('public_verification_lookups')->insert([
            'id' => (string) Str::uuid(), 'channel' => $channel, 'reference_hash' => hash('sha256', mb_strtoupper($reference)),
            'policy_certificate_id' => null, 'document_id' => $d->id, 'result' => $result, 'token_presented' => $token !== null && $token !== '',
            'request_fingerprint_hash' => hash('sha256', $fingerprint), 'occurred_at' => now(),
        ]);
        $type = app(\App\Application\Documents\Engine\DocumentRegister::class)->describe((string) $d->document_type_code);
        $successor = $d->superseded_by_document_id ? \App\Models\Document::find($d->superseded_by_document_id) : null;
        $product = $policy?->proposal?->offer?->product;

        return [
            'result' => $result, 'reference' => $reference,
            'carrier_name' => $policy?->carrier?->party?->display_name, 'product_class' => $product?->line_code, 'product_name' => $product?->name,
            'policy_status' => $policy?->status,
            'coverage_starts_at' => ($d->valid_from ?? $policy?->coverage_starts_at)?->toIso8601String(),
            'coverage_ends_at' => ($d->valid_until ?? $policy?->coverage_ends_at)?->toIso8601String(),
            'checked_at' => now()->toIso8601String(),
            'document' => [
                'document_number' => $d->document_number ?? ($d->provenance['carrier_document_number'] ?? null),
                'document_type_code' => $d->document_type_code, 'document_type_id' => $d->document_type_id,
                'title_en' => $type['name_en'], 'title_fr' => $type['name_fr'], 'status' => $status,
                'issuer_type' => $d->issuer_type, 'issuer_name' => $d->issuer_type === 'PLATFORM' ? 'OpesInsure' : $policy?->carrier?->party?->display_name,
                'issued_at' => ($d->issued_at ?? $d->created_at)?->toIso8601String(), 'policy_reference' => $policy?->policy_number,
                'language' => $d->language, 'sha256' => $d->sha256, 'carrier_original' => (bool) $d->is_carrier_original,
                'vehicle' => $d->subject_type === 'VEHICLE' ? $d->subject_key : null,
                'replaced_by' => $successor ? ($successor->document_number ?? $successor->provenance['carrier_document_number'] ?? $successor->verification_code) : null,
            ],
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
