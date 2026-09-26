<?php

declare(strict_types=1);

namespace App\Application\Documents\Security;

use App\Application\Documents\Engine\DocumentEngine;
use App\Application\Documents\Engine\DocumentRegister;
use App\Application\Shared\CanonicalJson;
use App\Models\Document;
use App\Models\Policy;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Privacy-safe public verification of an issued document (crypto spec §10, §14, §27, §45, §46; security
 * matrix §7). The registry record is authoritative: the QR / short code only locates it.
 *
 * Result code (canonical): VALID | DEMO_VALID | EXPIRED | CANCELLED | REVOKED | SUPERSEDED | REPLACED |
 * NOT_YET_EFFECTIVE | INVALID_TOKEN | HASH_MISMATCH | SIGNATURE_INVALID | POTENTIAL_TAMPERING | UNVERIFIABLE.
 * Integrity is re-checked on every lookup of a snapshot-issued document: stored file SHA-256, snapshot
 * hash, and the platform signature when present. Field consistency: a presented document number that
 * differs from the registry is POTENTIAL_TAMPERING (QR transplanted onto another document).
 *
 * Disclosure by confidentiality class:
 *  PUBLIC_PROOF (PUBLIC_VERIFY): status, issuer, document number, dates, masked holder, masked policy
 *    reference, product class/name, insured object (registration FULL|PARTIAL by config);
 *  MINIMAL (CUSTOMER_PRIVATE): status, issuer, type, masked number, dates — no holder, no policy, no product;
 *  RESTRICTED (medical / financial / insurer-confidential / internal / regulatory): status, issuer, type,
 *    masked number, issue date only. Never medical, claim-financial, beneficiary, bank, KYC or reinsurance data.
 */
final class DocumentVerificationPresenter
{
    public const CODES = ['VALID', 'VALID_OFFLINE_SIGNATURE_ONLY', 'EXPIRED', 'CANCELLED', 'REVOKED', 'SUPERSEDED', 'REPLACED', 'NOT_YET_EFFECTIVE', 'INVALID_TOKEN',
        'HASH_MISMATCH', 'SIGNATURE_INVALID', 'SIGNATURE_CERTIFICATE_REVOKED', 'STOCK_SERIAL_COMPROMISED', 'HOLOGRAM_SERIAL_COMPROMISED', 'POTENTIAL_TAMPERING', 'DEMO_VALID', 'UNVERIFIABLE'];

    public function __construct(private DocumentSigner $signer, private DocumentRegister $register, private CanonicalJson $json) {}

    public static function disclosure(Document $d): string
    {
        $class = $d->confidentiality_class ?: DocumentSecurityProfile::classFromLevel((string) ($d->security_level ?? 'CUSTOMER_PRIVATE'));

        return match ($class) {
            'PUBLIC_VERIFY' => 'PUBLIC_PROOF',
            'CUSTOMER_PRIVATE' => 'MINIMAL',
            default => 'RESTRICTED',
        };
    }

    /**
     * @param  array{token?: string|null, document_number?: string|null}  $presented
     * @return array{code: string, legacy: string, disclosure: string, integrity: array<string, mixed>}
     */
    public function evaluate(Document $d, ?Policy $policy, array $presented = []): array
    {
        $status = DocumentEngine::effectiveStatus($d);
        $integrity = $this->integrity($d);
        $token = $presented['token'] ?? null;
        $code = match (true) {
            $token !== null && $token !== '' && $d->verification_token_hash !== null && ! hash_equals((string) $d->verification_token_hash, VerificationCredentials::tokenHash($token)) => 'INVALID_TOKEN',
            ($integrity['file'] ?? null) === 'HASH_MISMATCH' => 'HASH_MISMATCH',
            ($integrity['signature'] ?? null) === 'SIGNATURE_INVALID' => 'SIGNATURE_INVALID',
            ($integrity['snapshot'] ?? null) === 'MISMATCH' => 'POTENTIAL_TAMPERING',
            isset($presented['document_number']) && $presented['document_number'] !== '' && $d->document_number
                && VerificationCredentials::normalize((string) $presented['document_number']) !== VerificationCredentials::normalize((string) $d->document_number) => 'POTENTIAL_TAMPERING',
            $status === 'SUPERSEDED' => 'SUPERSEDED',
            $status === 'REPLACED' => 'REPLACED',
            $status === 'REVOKED' => 'REVOKED',
            $status === 'EXPIRED' => 'EXPIRED',
            $status === 'CANCELLED' => 'CANCELLED',
            in_array($status, ['DRAFT', 'GENERATED', 'PENDING_SIGNATURE'], true) || (bool) $d->valid_from?->isFuture() => 'NOT_YET_EFFECTIVE',
            ($integrity['file'] ?? null) === 'MISSING' => 'UNVERIFIABLE',
            default => $this->policyCode($d, $policy),
        };
        if ($code === 'VALID' && (($d->provenance['demo_watermark'] ?? false) === true)) {
            $code = 'DEMO_VALID';
        }
        $legacy = match ($code) {
            'VALID', 'DEMO_VALID' => 'valid', 'NOT_YET_EFFECTIVE' => 'not_yet_active', 'INVALID_TOKEN' => 'not_found',
            'HASH_MISMATCH', 'SIGNATURE_INVALID', 'POTENTIAL_TAMPERING' => 'invalid', 'UNVERIFIABLE' => $policy && self::disclosure($d) === 'PUBLIC_PROOF' && ($integrity['file'] ?? null) !== 'MISSING'
                ? \App\Application\Certificates\PublicVerificationService::resultFor($policy, null) : 'invalid',
            default => strtolower($code),
        };

        return ['code' => $code, 'legacy' => $legacy, 'disclosure' => self::disclosure($d), 'integrity' => $integrity];
    }

    /**
     * Privacy-safe payload for the public verification channels.
     *
     * @param  array{code: string, legacy: string, disclosure: string, integrity: array<string, mixed>}  $eval
     * @return array<string, mixed>
     */
    public function publicPayload(Document $d, ?Policy $policy, array $eval, string $reference): array
    {
        $type = $this->register->describe((string) $d->document_type_code);
        $disclosure = $eval['disclosure'];
        $public = $disclosure === 'PUBLIC_PROOF';
        $minimal = $disclosure === 'MINIMAL';
        $product = $policy?->proposal?->offer?->product;
        $successor = $d->superseded_by_document_id ? Document::find($d->superseded_by_document_id) : null;
        $issuer = $d->issuer_type === 'PLATFORM' ? 'OpesInsure' : $policy?->carrier?->party?->display_name;
        $number = $d->document_number ?? ($d->provenance['carrier_document_number'] ?? null);
        $from = ($d->valid_from ?? $policy?->coverage_starts_at)?->toIso8601String();
        $until = ($d->valid_until ?? $policy?->coverage_ends_at)?->toIso8601String();
        $subject = null;
        if ($public && $d->subject_type === 'VEHICLE' && $d->subject_key) {
            $subject = config('document_security.verification.registration_disclosure') === 'PARTIAL' ? self::mask((string) $d->subject_key, 3) : $d->subject_key;
        }
        $holder = $public ? self::maskName((string) ($policy?->party?->display_name ?? '')) : null;

        $effective = DocumentEngine::effectiveStatus($d);

        // Security Matrix §7: only the allow-listed privacy-safe fields leave this method (sanitizePublic).
        return SecurityMatrix::sanitizePublic([
            'result' => $eval['legacy'], 'verification_result' => $eval['code'], 'reference' => $reference, 'disclosure' => $disclosure,
            // §8: lifecycle without reasons or actors (a revocation reason may reveal investigation findings).
            'lifecycle' => self::publicLifecycle($d, $effective),
            // §6 SEAL-08 / §5 WM-STATUS: revoked and replaced documents keep verifying, with their status seal.
            'status_seal' => match ($effective) { 'REVOKED' => 'SEAL-08', default => $d->duplicate_of_document_id ? 'SEAL-07' : null },
            'carrier_name' => $issuer === 'OpesInsure' ? $policy?->carrier?->party?->display_name : $issuer,
            'product_class' => $public ? $product?->line_code : null, 'product_name' => $public ? $product?->name : null,
            'policy_status' => $public ? $policy?->status : null,
            'coverage_starts_at' => $public || $minimal ? $from : null, 'coverage_ends_at' => $public || $minimal ? $until : null,
            'checked_at' => now()->toIso8601String(),
            'notice' => 'QR / code presence is not proof: this registry status is authoritative. / La présence du QR ne prouve rien : seul ce statut fait foi.',
            'document' => [
                'document_number' => $public ? $number : ($number ? self::mask((string) $number, 4) : null),
                'masked_document_number' => $number ? self::mask((string) $number, 4) : null,
                'document_type_code' => $d->document_type_code, 'document_type_id' => $d->document_type_id,
                'canonical_spec_id' => $type['canonical_spec_id'] ?? null,
                'title_en' => $type['name_en'], 'title_fr' => $type['name_fr'], 'status' => DocumentEngine::effectiveStatus($d),
                'issuer_type' => $d->issuer_type, 'issuer_name' => $issuer,
                'issued_at' => ($d->issued_at ?? $d->created_at)?->toIso8601String(),
                'valid_from' => $public || $minimal ? $from : null, 'valid_until' => $public || $minimal ? $until : null,
                'policy_reference' => $public && $policy?->policy_number ? self::mask((string) $policy->policy_number, 4) : null,
                'holder' => $holder,
                'language' => $d->language, 'sha256' => $d->sha256, 'carrier_original' => (bool) $d->is_carrier_original,
                'vehicle' => $subject,
                'security_tier' => $d->security_tier, 'confidentiality_class' => $d->confidentiality_class,
                'signature_status' => $eval['integrity']['signature'] ?? null,
                'replaced_by' => $successor ? ($public ? ($successor->document_number ?? $successor->provenance['carrier_document_number'] ?? $successor->verification_code)
                    : self::mask((string) ($successor->document_number ?? $successor->provenance['carrier_document_number'] ?? ''), 4)) : null,
                'revoked_at' => $effective === 'REVOKED' ? $d->status_changed_at?->toIso8601String() : null,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private static function publicLifecycle(Document $d, string $effective): array
    {
        return ['status' => $effective, 'superseded_by' => $d->superseded_by_document_id !== null, 'replacement_of' => $d->supersedes_document_id !== null,
            'duplicate' => $d->duplicate_of_document_id !== null, 'revoked' => $effective === 'REVOKED',
            'revoked_at' => $effective === 'REVOKED' ? $d->status_changed_at?->toIso8601String() : null, 'verifies_as' => $effective === 'REVOKED' ? 'REVOKED' : null];
    }

    /**
     * §8 revocation / replacement record of one document (internal channels only: carries actor and reasons).
     *
     * @return array<string, mixed>
     */
    public function revocationRecord(Document $d, ?Policy $policy = null): array
    {
        $effective = DocumentEngine::effectiveStatus($d);
        $revoked = $effective === 'REVOKED';

        return [
            'original_document_id' => $d->id, 'status' => $effective,
            'superseded_by' => $d->superseded_by_document_id, 'replacement_of' => $d->supersedes_document_id, 'duplicate_of' => $d->duplicate_of_document_id,
            'revoked_at' => $revoked ? $d->status_changed_at?->toIso8601String() : null, 'revoked_by' => $revoked ? $d->status_changed_by : null,
            'revocation_reason' => $revoked ? $d->status_reason : null, 'replacement_reason' => $d->replacement_reason,
            'verification_result' => $this->evaluate($d, $policy)['code'],
        ];
    }

    /** @return array<string, mixed> */
    private function integrity(Document $d): array
    {
        if ($d->content_hash_sha256 === null) {
            return ['file' => 'NOT_CHECKED', 'snapshot' => 'NOT_APPLICABLE', 'signature' => null];
        }
        $out = ['file' => 'MISSING', 'snapshot' => 'NOT_CHECKED', 'signature' => null];
        try {
            $disk = Storage::disk((string) config('lifecycle.documents_disk', 'local'));
            if ($d->storage_key && $disk->exists($d->storage_key)) {
                $out['file'] = hash_equals((string) $d->sha256, hash('sha256', (string) $disk->get($d->storage_key))) ? 'MATCH' : 'HASH_MISMATCH';
            }
        } catch (Throwable) {
            $out['file'] = 'MISSING';
        }
        $snapshot = is_array($d->issuance_snapshot) ? $d->issuance_snapshot : json_decode((string) $d->getRawOriginal('issuance_snapshot'), true);
        if (is_array($snapshot)) {
            $out['snapshot'] = hash_equals((string) $d->snapshot_hash, $this->json->hash($snapshot)) ? 'MATCH' : 'MISMATCH';
        }
        $sig = is_array($d->signature) ? $d->signature : json_decode((string) $d->getRawOriginal('signature'), true);
        $out['signature'] = $this->signer->verify(is_array($sig) ? $sig : null);
        if (is_array($sig) && ($sig['status'] ?? null) === 'SIGNED') {
            $p = (array) ($sig['payload'] ?? []);
            if (($p['final_file_hash'] ?? null) !== $d->sha256 || ($p['snapshot_hash'] ?? null) !== $d->snapshot_hash) {
                $out['signature'] = 'SIGNATURE_INVALID';
            }
        }

        return $out;
    }

    private function policyCode(Document $d, ?Policy $policy): string
    {
        if (! $policy || self::disclosure($d) !== 'PUBLIC_PROOF') {
            return 'VALID';
        }

        return match (\App\Application\Certificates\PublicVerificationService::resultFor($policy, null)) {
            'valid' => 'VALID', 'expired' => 'EXPIRED', 'cancelled' => 'CANCELLED', 'not_yet_active' => 'NOT_YET_EFFECTIVE',
            default => 'UNVERIFIABLE', // suspended / invalid policy state: never answer VALID
        };
    }

    /** JU*** NS*** (crypto spec §27). */
    public static function maskName(string $name): ?string
    {
        $parts = array_filter(preg_split('/\s+/', trim($name)) ?: []);

        return $parts ? implode(' ', array_map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 2)).'***', $parts)) : null;
    }

    public static function mask(string $value, int $visible = 4): string
    {
        $len = mb_strlen($value);

        return $len <= $visible ? str_repeat('*', $len) : str_repeat('*', $len - $visible).mb_substr($value, -$visible);
    }
}
