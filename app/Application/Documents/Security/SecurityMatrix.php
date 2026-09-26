<?php

declare(strict_types=1);

namespace App\Application\Documents\Security;

use App\Application\DocumentCatalogue\CanonicalDocumentSpec;
use RuntimeException;

/**
 * Security Matrix v1 §4–11 (docs/spec/canonical/OpesInsure_220_Document_Security_Matrix_v1.md), which the JSON
 * conversion of the canonical spec dropped: physical security profiles (PS-01..04), dynamic watermark profiles
 * (WM-*), seal profiles (SEAL-01..08), public verification rules, revocation/replacement rules, the 15-step
 * security issuance gate, the developer instruction and the locked principles.
 *
 * The markdown is parsed (never retyped) so the dictionary always matches the owner's source. Per-document profile
 * assignment is PLATFORM_NORMALIZED from the §3 matrix columns (confidentiality, category, seal / watermark / UV /
 * HOLO / STOCK) plus the §4 PS-04 recommendation list; the method is recorded with every assignment.
 */
final class SecurityMatrix
{
    public const SOURCE = 'OWNER_SECURITY_MATRIX_V1';

    public const WATERMARKS = ['WM-PUBLIC-PROOF', 'WM-PRIVATE', 'WM-FINANCE', 'WM-CLAIMS', 'WM-MEDICAL', 'WM-STATUS'];

    public const SEALS = ['SEAL-01', 'SEAL-02', 'SEAL-03', 'SEAL-04', 'SEAL-05', 'SEAL-06', 'SEAL-07', 'SEAL-08'];

    public const PHYSICAL = ['PS-01', 'PS-02', 'PS-03', 'PS-04'];

    /** WM-STATUS overlays (§5). */
    public const STATUS_OVERLAYS = ['DRAFT', 'COPY', 'DUPLICATE', 'DEMONSTRATION', 'REVOKED', 'SUPERSEDED', 'CANCELLED', 'EXPIRED', 'VOID', 'REVERSED'];

    /** Provider / pre-authorization guarantee documents (SEAL-05). */
    public const PROVIDER_SPECS = ['DOC-064', 'DOC-065', 'DOC-066', 'DOC-067', 'DOC-068', 'DOC-069', 'DOC-070', 'DOC-071', 'DOC-072'];

    /**
     * §7 public output allow-list, as payload keys of DocumentVerificationPresenter::publicPayload(). Anything
     * else is stripped before a public answer leaves the service.
     */
    public const PUBLIC_TOP_KEYS = ['result', 'verification_result', 'status', 'reference', 'disclosure', 'carrier_name', 'product_class', 'product_name',
        'policy_status', 'coverage_starts_at', 'coverage_ends_at', 'checked_at', 'notice', 'document', 'lifecycle', 'status_seal'];

    public const PUBLIC_DOCUMENT_KEYS = ['document_number', 'masked_document_number', 'document_type_code', 'document_type_id', 'canonical_spec_id',
        'title_en', 'title_fr', 'status', 'issuer_type', 'issuer_name', 'issued_at', 'valid_from', 'valid_until', 'policy_reference', 'holder',
        'language', 'sha256', 'carrier_original', 'vehicle', 'security_tier', 'confidentiality_class', 'signature_status', 'replaced_by', 'revoked_at'];

    /** §7 "Do not expose publicly": key fragments refused anywhere in a public payload. */
    public const FORBIDDEN_PUBLIC = [
        'medical' => ['diagnos', 'medical', 'icd', 'treatment', 'prescription'],
        'claim_financial' => ['claim_amount', 'reserve', 'settlement_amount', 'indemnity', 'payout'],
        'beneficiary' => ['beneficiar', 'allocation'],
        'bank_payment' => ['iban', 'bank', 'account_number', 'card', 'msisdn', 'payment_reference'],
        'kyc' => ['kyc', 'id_number', 'national_id', 'passport', 'date_of_birth', 'dob'],
        'reinsurance' => ['reinsur', 'cession', 'treaty'],
        'investigation' => ['investigation', 'fraud', 'finding'],
    ];

    /** @var array<string, mixed>|null */
    private ?array $parsed = null;

    public function __construct(private ?string $path = null) {}

    public function path(): string
    {
        return $this->path ?? (string) config('document_security.security_matrix_path', base_path('docs/spec/canonical/OpesInsure_220_Document_Security_Matrix_v1.md'));
    }

    public function available(): bool
    {
        return is_file($this->path());
    }

    public function sourceHash(): string
    {
        return $this->available() ? (string) hash_file('sha256', $this->path()) : '';
    }

    /**
     * @return array{physical: array<string, array<string, mixed>>, watermarks: array<string, array<string, mixed>>, seals: array<string, array<string, mixed>>,
     *   verification: array{public_output: list<string>, never_public: list<string>, rule: string}, revocation: array{fields: list<string>, rules: list<string>},
     *   issuance_gate: list<string>, instruction: string, principles: list<string>}
     */
    public function parse(): array
    {
        if ($this->parsed !== null) {
            return $this->parsed;
        }
        if (! $this->available()) {
            throw new RuntimeException('Security matrix source missing: '.$this->path());
        }
        $sections = [];
        $current = null;
        foreach (preg_split('/\R/', (string) file_get_contents($this->path())) ?: [] as $line) {
            if (preg_match('/^# (\d+)\.\s/', $line, $m)) {
                $current = (int) $m[1];
                $sections[$current] = [];

                continue;
            }
            if ($current !== null) {
                $sections[$current][] = $line;
            }
        }
        foreach ([4, 5, 6, 7, 8, 9, 10, 11] as $n) {
            if (! isset($sections[$n])) {
                throw new RuntimeException("Security matrix §$n not found in ".$this->path());
            }
        }

        $physical = [];
        foreach (self::subsections($sections[4]) as $title => $lines) {
            [$code, $name] = array_map('trim', explode('—', $title, 2)) + [1 => ''];
            $bullets = self::bullets($lines);
            $recommended = [];
            foreach ($bullets as $b) {
                if (preg_match_all('/DOC-(\d{3})/', $b, $m)) {
                    foreach ($m[1] as $id) {
                        $recommended['DOC-'.$id] = str_starts_with($b, 'selected') ? 'SELECTED' : 'RECOMMENDED';
                    }
                }
            }
            $prose = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== '' && ! str_starts_with($l, '-') && ! str_ends_with($l, ':') && $l !== '---'));
            $physical[$code] = ['code' => $code, 'name' => $name, 'controls' => array_values(array_filter($bullets, fn ($b) => ! preg_match('/DOC-\d{3}/', $b))),
                'usage' => $prose, 'recommended_for' => $recommended, 'adds_to' => $code === 'PS-01' ? null : 'PS-01',
                'requires_hardware' => $code !== 'PS-01', 'status' => 'CONFIG_REQUIRED'];
            // DOC-138/139 appear as "DOC-138/139": expand the shorthand.
            foreach ($bullets as $b) {
                if (preg_match('/DOC-(\d{3})\/(\d{3})/', $b, $m)) {
                    $physical[$code]['recommended_for']['DOC-'.$m[2]] = 'SELECTED';
                }
            }
        }

        $watermarks = [];
        foreach (self::subsections($sections[5]) as $title => $lines) {
            $code = trim($title);
            $prose = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== '' && ! str_starts_with($l, '-') && $l !== '---'));
            $items = array_map(fn ($b) => rtrim($b, ';.'), self::bullets($lines));
            $watermarks[$code] = ['code' => $code, 'purpose' => $prose[0] ?? null, 'elements' => $items, 'overlay' => $code === 'WM-STATUS'];
        }

        $seals = [];
        foreach ($sections[6] as $line) {
            if (preg_match('/^- \*\*(SEAL-\d{2}) ([^*]+)\*\* — (.+)$/u', trim($line), $m)) {
                $seals[$m[1]] = ['code' => $m[1], 'name' => trim($m[2]), 'purpose' => rtrim(trim($m[3]), '.')];
            }
        }
        $sealRule = self::prose($sections[6]);

        [$public, $never] = self::splitLists($sections[7], 'Recommended public output', 'Do not expose publicly');

        $revocationRules = array_values(array_filter(self::prose($sections[8]), fn ($l) => ! str_starts_with($l, 'Every')));

        $gate = [];
        foreach ($sections[9] as $line) {
            if (preg_match('/^\d+\.\s+(.+?);?\.?$/', trim($line), $m)) {
                $gate[] = rtrim($m[1], ';.');
            }
        }
        $instruction = '';
        foreach ($sections[10] as $line) {
            if (str_starts_with(trim($line), '>')) {
                $instruction .= trim(str_replace('**', '', ltrim(trim($line), '> ')));
            }
        }
        $principles = [];
        foreach ($sections[11] as $line) {
            if (preg_match('/^\d+\.\s+(.+)$/', trim($line), $m)) {
                $principles[] = rtrim($m[1], '.');
            }
        }

        return $this->parsed = [
            'physical' => $physical, 'watermarks' => $watermarks, 'seals' => $seals + ['_rule' => ['text' => $sealRule[0] ?? null]],
            'verification' => ['public_output' => $public, 'never_public' => $never, 'rule' => self::prose($sections[7])[0] ?? ''],
            'revocation' => ['fields' => array_map(fn ($b) => rtrim($b, ';.'), self::bullets($sections[8])), 'rules' => $revocationRules],
            'issuance_gate' => $gate, 'instruction' => $instruction, 'principles' => $principles,
        ];
    }

    /**
     * Per-document profile assignment (PLATFORM_NORMALIZED from the §3 row).
     *
     * @param  array<string, mixed>  $profile  CanonicalDocumentSpec::profile()
     * @param  array<string, string>  $recommendedPs04  spec id => RECOMMENDED|SELECTED
     * @return array{watermark_profile_code: string|null, seal_profile_codes: list<string>, physical_profile_codes: list<string>, method: array<string, string>}
     */
    public static function assign(string $specId, string $category, array $profile, array $recommendedPs04 = []): array
    {
        $c = $profile['controls'];
        $req = fn (string $k) => (string) ($c[$k]['requirement'] ?? 'PENDING_VERIFICATION');
        $class = (string) ($profile['confidentiality_class'] ?? 'CUSTOMER_PRIVATE');
        $classes = (array) ($profile['confidentiality_classes'] ?? [$class]);
        $rank = CanonicalDocumentSpec::tierRank($profile['tier_floor'] ?? null);

        $wm = null;
        if ($req('watermark') !== 'NOT_REQUIRED') {
            $wm = match (true) {
                in_array('MEDICAL_RESTRICTED', $classes, true) || ($category === 'D' && $class === 'MEDICAL_RESTRICTED') => 'WM-MEDICAL',
                $category === 'J' => 'WM-CLAIMS',
                $category === 'K' || $class === 'FINANCIAL_RESTRICTED' => 'WM-FINANCE',
                $class === 'PUBLIC_VERIFY' || in_array('PUBLIC_VERIFY', $classes, true) && $req('public_verification') === 'REQUIRED' => 'WM-PUBLIC-PROOF',
                default => 'WM-PRIVATE',
            };
        }

        $seals = [];
        if ($req('seal') !== 'NOT_REQUIRED' && $req('seal') !== 'PENDING_VERIFICATION' || $rank >= 3) {
            $seals[] = 'SEAL-02';
            if ($rank >= 3) {
                $seals[] = 'SEAL-01';
            }
            if ($category === 'K' || ($c['seal']['variant'] ?? null) === 'FINANCE') {
                $seals[] = 'SEAL-03';
            }
            if ($category === 'J') {
                $seals[] = 'SEAL-04';
            }
            if (in_array($specId, self::PROVIDER_SPECS, true)) {
                $seals[] = 'SEAL-05';
            }
        }
        sort($seals);

        $physical = [];
        $on = fn (string $k) => ! in_array($req($k), ['NOT_REQUIRED', 'PENDING_VERIFICATION'], true);
        $ps04 = $on('secure_stock') || isset($recommendedPs04[$specId]);
        if ($on('uv') || $on('hologram') || $ps04) {
            $physical[] = 'PS-01';
            if ($on('uv')) {
                $physical[] = 'PS-02';
            }
            if ($on('hologram')) {
                $physical[] = 'PS-03';
            }
            if ($ps04) {
                $physical[] = 'PS-04';
            }
        }

        return ['watermark_profile_code' => $wm, 'seal_profile_codes' => $seals, 'physical_profile_codes' => $physical, 'method' => [
            'watermark' => 'PLATFORM_NORMALIZED: §3 WM column + confidentiality class / category → §5 profile',
            'seal' => 'PLATFORM_NORMALIZED: §3 SEAL column + tier + category → §6 profiles (SEAL-06/07/08 are applied from issuance state)',
            'physical' => 'PLATFORM_NORMALIZED: §3 UV/HOLO/STOCK columns + §4 PS-04 recommendation list',
        ]];
    }

    /**
     * Seals the renderer may actually print for one issuance (§6: "A seal is only valid when the corresponding backend
     * authority and issuance state exists"). Returns code => APPLIED | CONFIG_REQUIRED | NOT_APPLICABLE with the reason.
     *
     * @param  list<string>  $assigned
     * @param  array{issuer_type?: string, payment_reconciled?: bool, claim_authorized?: bool, provider_guarantee?: bool, duplicate?: bool, corporate_seal_artwork?: bool}  $state
     * @return array<string, array{status: string, reason: string}>
     */
    public static function sealStates(array $assigned, array $state): array
    {
        $out = [];
        foreach (self::SEALS as $code) {
            $assignedHere = in_array($code, $assigned, true);
            $out[$code] = match ($code) {
                'SEAL-01' => ! $assignedHere ? self::na() : (($state['corporate_seal_artwork'] ?? false) ? self::ok('verified corporate seal artwork on file')
                    : ['status' => 'CONFIG_REQUIRED', 'reason' => 'no verified corporate seal artwork recorded (Physical security assets)']),
                'SEAL-02' => $assignedHere ? self::ok('registry-backed authentication seal') : self::na(),
                'SEAL-03' => ! $assignedHere ? self::na() : (($state['payment_reconciled'] ?? false) ? self::ok('reconciled payment') : ['status' => 'NOT_APPLICABLE', 'reason' => 'no reconciled payment']),
                'SEAL-04' => ! $assignedHere ? self::na() : (($state['claim_authorized'] ?? false) ? self::ok('authorized claim decision') : ['status' => 'NOT_APPLICABLE', 'reason' => 'no claim authorization']),
                'SEAL-05' => ! $assignedHere ? self::na() : (($state['provider_guarantee'] ?? false) ? self::ok('provider guarantee issued') : ['status' => 'NOT_APPLICABLE', 'reason' => 'no provider guarantee']),
                'SEAL-06' => ($state['issuer_type'] ?? null) === 'BROKER' ? self::ok('broker-issued') : self::na(),
                'SEAL-07' => ($state['duplicate'] ?? false) ? self::ok('replacement / duplicate issuance') : self::na(),
                // Issued files are immutable: the revocation seal is a verification-time overlay only.
                'SEAL-08' => ['status' => 'NOT_APPLICABLE', 'reason' => 'verification-time overlay (REVOKED status)'],
            };
        }

        return $out;
    }

    /** WM-STATUS overlay for a document status / environment (§5). */
    public static function statusOverlay(?string $status, bool $demo = false, bool $duplicate = false): ?string
    {
        $status = $status ? mb_strtoupper($status) : null;

        return match (true) {
            $status !== null && in_array($status, ['DRAFT', 'REVOKED', 'SUPERSEDED', 'CANCELLED', 'EXPIRED', 'VOID', 'REVERSED'], true) => $status,
            $status === 'REPLACED' => 'SUPERSEDED',
            $duplicate => 'DUPLICATE',
            $demo => 'DEMONSTRATION',
            default => null,
        };
    }

    /**
     * §7 enforcement: keep only allow-listed keys and refuse any forbidden category, whatever a caller added.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function sanitizePublic(array $payload): array
    {
        $out = array_intersect_key($payload, array_flip(self::PUBLIC_TOP_KEYS));
        if (isset($out['document']) && is_array($out['document'])) {
            $out['document'] = array_intersect_key($out['document'], array_flip(self::PUBLIC_DOCUMENT_KEYS));
        }
        if (isset($out['lifecycle']) && is_array($out['lifecycle'])) {
            $out['lifecycle'] = array_intersect_key($out['lifecycle'], array_flip(['status', 'superseded_by', 'replacement_of', 'duplicate', 'revoked', 'revoked_at', 'verifies_as']));
        }

        return self::stripForbidden($out);
    }

    /** @return list<string> forbidden categories found in a payload (keys only) */
    public static function forbiddenKeys(array $payload, string $prefix = ''): array
    {
        $found = [];
        foreach ($payload as $k => $v) {
            $key = mb_strtolower((string) $k);
            foreach (self::FORBIDDEN_PUBLIC as $cat => $needles) {
                foreach ($needles as $n) {
                    if (str_contains($key, $n)) {
                        $found[] = $cat.':'.$prefix.$k;
                    }
                }
            }
            if (is_array($v)) {
                array_push($found, ...self::forbiddenKeys($v, $prefix.$k.'.'));
            }
        }

        return array_values(array_unique($found));
    }

    private static function stripForbidden(array $payload): array
    {
        foreach ($payload as $k => $v) {
            $key = mb_strtolower((string) $k);
            foreach (self::FORBIDDEN_PUBLIC as $needles) {
                foreach ($needles as $n) {
                    if (str_contains($key, $n)) {
                        unset($payload[$k]);

                        continue 3;
                    }
                }
            }
            if (is_array($v)) {
                $payload[$k] = self::stripForbidden($v);
            }
        }

        return $payload;
    }

    private static function ok(string $reason): array
    {
        return ['status' => 'APPLIED', 'reason' => $reason];
    }

    private static function na(): array
    {
        return ['status' => 'NOT_APPLICABLE', 'reason' => 'not assigned to this document'];
    }

    /** @return array<string, list<string>> "## title" => lines */
    private static function subsections(array $lines): array
    {
        $out = [];
        $cur = null;
        foreach ($lines as $l) {
            if (preg_match('/^## (.+)$/', $l, $m)) {
                $cur = trim($m[1]);
                $out[$cur] = [];
            } elseif ($cur !== null) {
                $out[$cur][] = $l;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function bullets(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            if (preg_match('/^\s*-\s+(.+)$/', $l, $m)) {
                $out[] = rtrim(trim($m[1]), ';.');
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function prose(array $lines): array
    {
        return array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== '' && $l !== '---' && ! str_starts_with($l, '-') && ! str_ends_with($l, ':')));
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private static function splitLists(array $lines, string $first, string $second): array
    {
        $a = $b = [];
        $mode = null;
        foreach ($lines as $l) {
            $t = trim($l);
            if (str_starts_with($t, $first)) {
                $mode = 'a';
            } elseif (str_starts_with($t, $second)) {
                $mode = 'b';
            } elseif (preg_match('/^-\s+(.+)$/', $t, $m) && $mode) {
                ${$mode}[] = rtrim(trim($m[1]), ';.');
            }
        }

        return [$a, $b];
    }
}
