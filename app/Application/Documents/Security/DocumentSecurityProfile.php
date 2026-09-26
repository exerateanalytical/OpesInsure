<?php

declare(strict_types=1);

namespace App\Application\Documents\Security;

use App\Application\DocumentCatalogue\CanonicalDocumentSpec;
use App\Models\DocumentIssuanceProfile;

/**
 * Effective security profile of one document at issuance: the catalogue type's canonical profile (tier
 * floor, controls, confidentiality, access profiles, master shell), never lowered by an issuance profile
 * or visual choice (manifest invariant "Security cannot be downgraded for visual simplicity").
 *
 * Each control gets an applied status:
 *  APPLIED           rendered / enforced digitally (QR, hash, watermark, guilloche, microtext, seal, anti-copy,
 *                    revocation, status verification, signature when a key is configured);
 *  CONFIG_REQUIRED   required but its prerequisite is not provisioned (signing key, maker-checker evidence,
 *                    TSA, physical secure print: UV / hologram / secure stock);
 *  NOT_APPLICABLE    not required for this document / tier.
 */
final class DocumentSecurityProfile
{
    public function __construct(private DocumentSigner $signer) {}

    /**
     * @param  array<string, mixed>  $type  DocumentRegister::describe()
     * @param  array{maker_checker_evidence?: string|null}  $context
     * @return array<string, mixed>
     */
    public function resolve(array $type, ?DocumentIssuanceProfile $issuance = null, array $context = []): array
    {
        $floor = $type['security_tier'] ?? null;
        $ceiling = $type['security_tier_ceiling'] ?? $floor;
        if ($floor === null) {
            // Type not (yet) profiled by the canonical spec: derived minimum from its catalogue level.
            $floor = ($type['security_level'] ?? null) === 'PUBLIC_VERIFIABLE' ? 'S3' : 'S2';
            $ceiling = $floor;
        }
        $physical = (bool) config('document_security.physical_issuance_enabled') && CanonicalDocumentSpec::tierRank($ceiling) === 5;
        $tier = $physical ? 'S5' : $floor;
        $rank = CanonicalDocumentSpec::tierRank($tier);
        $defined = (array) ($type['security_controls'] ?? []);
        $req = fn (string $c) => (string) ($defined[$c]['requirement'] ?? $this->tierDefault($c, $rank));
        $variant = fn (string $c) => $defined[$c]['variant'] ?? null;
        $on = fn (string $c) => in_array($req($c), ['REQUIRED', 'CONFIGURABLE'], true) || ($req($c) === 'OPTIONAL' && $rank >= 3);

        $signatureRequired = $req('signature') === 'REQUIRED' || $rank >= 3; // C2 (S3) and C3 (S4) require a digital signature
        $signatureIssue = $this->signer->configurationIssue();
        $makerChecker = in_array($req('maker_checker'), ['REQUIRED', 'CONFIGURABLE'], true) || $rank >= 4;
        $evidence = $context['maker_checker_evidence'] ?? null;

        $controls = [
            'numbering' => ['status' => 'APPLIED'],
            'qr' => ['status' => ($req('qr') === 'NOT_REQUIRED' && $rank < 2) ? 'NOT_APPLICABLE' : 'APPLIED', 'variant' => $variant('qr'),
                'note' => 'QR points to the authoritative registry; it is not proof by itself'],
            'hash' => ['status' => 'APPLIED', 'algorithm' => 'SHA-256'],
            'watermark' => ['status' => $req('watermark') === 'NOT_REQUIRED' ? 'NOT_APPLICABLE' : 'APPLIED', 'variant' => $variant('watermark') ?? ($rank >= 3 ? 'DYNAMIC' : 'LIGHT')],
            'guilloche' => ['status' => $req('guilloche') === 'NOT_REQUIRED' && $rank < 3 ? 'NOT_APPLICABLE' : 'APPLIED', 'variant' => $variant('guilloche') ?? ($rank >= 3 ? 'FULL' : 'LIGHT')],
            'microtext' => ['status' => $on('microtext') || $rank >= 3 ? 'APPLIED' : 'NOT_APPLICABLE', 'print_validation' => 'CONFIG_REQUIRED'],
            'anti_copy' => ['status' => $on('anti_copy') ? 'APPLIED' : 'NOT_APPLICABLE', 'note' => 'supplementary, not definitive proof'],
            'seal' => ['status' => $req('seal') === 'REQUIRED' || $rank >= 3 ? 'APPLIED' : 'NOT_APPLICABLE', 'profile' => 'SEAL-02 AUTHENTICATION',
                'corporate_seal_artwork' => 'CONFIG_REQUIRED'],
            'signature' => ['status' => $signatureRequired || $req('signature') === 'OPTIONAL' ? ($signatureIssue === null ? 'APPLIED' : ($signatureRequired ? 'CONFIG_REQUIRED' : 'NOT_APPLICABLE')) : 'NOT_APPLICABLE',
                'required' => $signatureRequired, 'variant' => $variant('signature'), 'reason' => $signatureIssue, 'pades' => 'CONFIG_REQUIRED'],
            'timestamp' => ['status' => $rank >= 4 ? 'CONFIG_REQUIRED' : 'NOT_APPLICABLE', 'note' => 'RFC 3161 TSA not provisioned'],
            'maker_checker' => ['status' => $makerChecker ? ($evidence ? 'APPLIED' : 'CONFIG_REQUIRED') : 'NOT_APPLICABLE', 'evidence' => $evidence, 'requirement' => $req('maker_checker')],
            'revocation' => ['status' => 'APPLIED', 'variant' => $variant('revocation')],
            'status_verification' => ['status' => 'APPLIED'],
            'public_verification' => ['status' => 'APPLIED', 'requirement' => $req('public_verification'), 'variant' => $variant('public_verification')],
            'audit' => ['status' => 'APPLIED'],
        ];
        foreach (CanonicalDocumentSpec::PHYSICAL_CONTROLS as $c) {
            // No secure-print operation exists yet: any physical control the spec asks for is CONFIG_REQUIRED.
            $controls[$c] = ['status' => in_array($req($c), ['NOT_REQUIRED', 'PENDING_VERIFICATION'], true) ? 'NOT_APPLICABLE' : 'CONFIG_REQUIRED',
                'requirement' => $req($c), 'variant' => $variant($c), 'physical' => true, 'note' => 'requires controlled secure printing, serial inventory and custody'];
        }

        // Security Matrix §4/§5/§6 profiles: assigned per type, applied from the actual issuance state.
        $profiles = self::matrixProfiles($type, $issuance, $context, $rank);
        $controls['watermark']['profile'] = $profiles['watermark'];
        $controls['seal']['profiles'] = $profiles['seals'];
        if ($controls['seal']['status'] === 'APPLIED' && ($profiles['seals']['SEAL-01']['status'] ?? null) === 'APPLIED') {
            $controls['seal']['corporate_seal_artwork'] = 'APPLIED';
        }
        foreach (CanonicalDocumentSpec::PHYSICAL_CONTROLS as $c) {
            $controls[$c]['profiles'] = $profiles['physical'];
        }

        $blocking = [];
        foreach (['signature', 'maker_checker'] as $c) {
            if ($controls[$c]['status'] === 'CONFIG_REQUIRED') {
                $blocking[] = $c;
            }
        }
        if ($physical) {
            foreach (CanonicalDocumentSpec::PHYSICAL_CONTROLS as $c) {
                if ($controls[$c]['status'] === 'CONFIG_REQUIRED') {
                    $blocking[] = $c;
                }
            }
        }

        return [
            'tier' => $tier, 'tier_floor' => $floor, 'tier_ceiling' => $ceiling, 'assurance' => ['S1' => 'C1', 'S2' => 'C1', 'S3' => 'C2', 'S4' => 'C3', 'S5' => 'C4'][$tier] ?? 'C1',
            'canonical_spec_id' => $type['canonical_spec_id'] ?? null, 'master_shell_code' => $type['master_shell_code'] ?? null,
            'confidentiality_class' => $type['confidentiality_class'] ?? self::classFromLevel((string) ($type['security_level'] ?? 'CUSTOMER_PRIVATE')),
            'access_profiles' => (array) ($type['access_profiles'] ?? []),
            'controls' => $controls, 'unmet_required_controls' => $blocking, 'profiles' => $profiles,
        ];
    }

    /**
     * @param  array<string, mixed>  $type
     * @param  array<string, mixed>  $context  issuer_type, payment_reconciled, claim_authorized, provider_guarantee, duplicate, status
     * @return array{watermark: array<string, mixed>, seals: array<string, array{status: string, reason: string}>, physical: array<string, array<string, mixed>>, status_overlay: string|null}
     */
    private static function matrixProfiles(array $type, ?DocumentIssuanceProfile $issuance, array $context, int $rank): array
    {
        $class = $type['confidentiality_class'] ?? self::classFromLevel((string) ($type['security_level'] ?? 'CUSTOMER_PRIVATE'));
        $wm = $type['watermark_profile_code'] ?? null;
        $wm ??= match ($class) {
            'PUBLIC_VERIFY' => 'WM-PUBLIC-PROOF', 'MEDICAL_RESTRICTED' => 'WM-MEDICAL', 'FINANCIAL_RESTRICTED' => 'WM-FINANCE', default => 'WM-PRIVATE',
        };
        $seals = (array) ($type['seal_profile_codes'] ?? []);
        if ($seals === [] && $rank >= 3) {
            $seals = ['SEAL-01', 'SEAL-02']; // unprofiled S3+ type: tier baseline (§2 S3 requires SEAL)
        }
        $carrierId = $context['carrier_id'] ?? $issuance?->carrier_id;
        $duplicate = (bool) ($context['duplicate'] ?? false);

        return [
            'watermark' => ['code' => $wm, 'status_profile' => 'WM-STATUS'],
            'seals' => SecurityMatrix::sealStates($seals, [
                'issuer_type' => $context['issuer_type'] ?? null, 'payment_reconciled' => (bool) ($context['payment_reconciled'] ?? false),
                'claim_authorized' => (bool) ($context['claim_authorized'] ?? false), 'provider_guarantee' => (bool) ($context['provider_guarantee'] ?? false),
                'duplicate' => $duplicate, 'corporate_seal_artwork' => PhysicalSecurityRegistry::corporateSealArtwork($carrierId),
            ]),
            'physical' => PhysicalSecurityRegistry::profileStates((array) ($type['physical_profile_codes'] ?? []), $carrierId),
            'status_overlay' => SecurityMatrix::statusOverlay($context['status'] ?? null, false, $duplicate),
        ];
    }

    /** Tier baseline when a control is not specified per document (document_system.security_tiers). */
    private function tierDefault(string $control, int $rank): string
    {
        $required = [
            1 => ['hash'], 2 => ['qr', 'hash', 'watermark'],
            3 => ['qr', 'hash', 'watermark', 'seal', 'microtext', 'guilloche', 'signature', 'revocation'],
            4 => ['qr', 'hash', 'watermark', 'seal', 'microtext', 'guilloche', 'signature', 'revocation', 'maker_checker'],
            5 => ['qr', 'hash', 'watermark', 'seal', 'microtext', 'guilloche', 'signature', 'revocation', 'maker_checker', 'uv', 'hologram', 'secure_stock'],
        ][$rank] ?? ['hash'];

        return in_array($control, $required, true) ? 'REQUIRED' : (in_array($control, CanonicalDocumentSpec::PHYSICAL_CONTROLS, true) ? 'NOT_REQUIRED' : 'OPTIONAL');
    }

    public static function classFromLevel(string $level): string
    {
        return array_flip(\App\Application\Documents\Engine\DocumentRegister::CONFIDENTIALITY_CLASS_MAP)[$level] ?? 'CUSTOMER_PRIVATE';
    }
}
