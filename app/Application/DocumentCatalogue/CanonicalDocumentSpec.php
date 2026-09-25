<?php

declare(strict_types=1);

namespace App\Application\DocumentCatalogue;

use RuntimeException;

/**
 * Reader + normalizer of the owner's canonical implementation specification
 * (docs/spec/canonical/OpesInsure_Canonical_Implementation_Specification_v1.json): document_system
 * (S1-S5 tiers, A4 zones, 15 field groups, control legend, confidentiality classes, access profiles,
 * shared security artifacts, 5 master shells, 220 per-document records). Pure: no database access.
 *
 * Normalization rules (all recorded in DOCUMENT_SPEC_GAP_AUDIT.md):
 *  - a dual tier "Sa/Sb" is floor a, ceiling b (quote shell: "S1 by default, S2 when insurer wants";
 *    motor shell: "S4 minimum, S5 for controlled physical originals"). The floor is never lowered.
 *  - a dual confidentiality "X/Y" keeps both; the most restrictive one is the stored class.
 *  - each control value is kept raw and normalized to REQUIRED | OPTIONAL | CONFIGURABLE | NOT_REQUIRED,
 *    with a variant (LIGHT, DYNAMIC, PRIVATE, S5 ...). Physical controls (uv, hologram, secure_stock)
 *    are flagged physical: they only count once real secure printing exists (CONFIG_REQUIRED).
 */
final class CanonicalDocumentSpec
{
    public const CONTROLS = ['qr', 'hash', 'watermark', 'seal', 'microtext', 'guilloche', 'anti_copy', 'signature', 'maker_checker', 'uv', 'hologram', 'secure_stock', 'revocation', 'public_verification'];

    public const PHYSICAL_CONTROLS = ['uv', 'hologram', 'secure_stock'];

    /** Restrictiveness order of confidentiality classes (higher = more restricted). */
    public const CONFIDENTIALITY_RANK = [
        'PUBLIC_VERIFY' => 0, 'CUSTOMER_PRIVATE' => 1, 'INSURER_CONFIDENTIAL' => 2, 'INTERNAL_RESTRICTED' => 2,
        'FINANCIAL_RESTRICTED' => 3, 'MEDICAL_RESTRICTED' => 4, 'REGULATORY_RESTRICTED' => 4,
    ];

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    public function __construct(private ?string $path = null) {}

    public function path(): string
    {
        return $this->path ?? (string) config('document_security.spec_path');
    }

    public function available(): bool
    {
        return is_file($this->path());
    }

    /** @return array<string, mixed> */
    public function raw(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        $path = $this->path();
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($data) || ! isset($data['document_system']['documents'])) {
            throw new RuntimeException('Canonical document specification missing or invalid: '.$path);
        }

        return $this->data = $data;
    }

    public function version(): string
    {
        $p = $this->raw()['package'] ?? [];

        return (string) ($p['version'] ?? $p['package_version'] ?? 'v1');
    }

    public function sourceHash(): string
    {
        return hash_file('sha256', $this->path()) ?: '';
    }

    /** @return array<string, array<string, mixed>> */
    public function documents(): array
    {
        return $this->raw()['document_system']['documents'];
    }

    /** @return array{floor: string, ceiling: string} */
    public static function tierRange(string $tier): array
    {
        preg_match_all('/S([1-5])/', $tier, $m);
        $levels = array_map('intval', $m[1] ?: [1]);

        return ['floor' => 'S'.min($levels), 'ceiling' => 'S'.max($levels)];
    }

    public static function tierRank(?string $tier): int
    {
        return $tier && preg_match('/^S([1-5])$/', $tier, $m) ? (int) $m[1] : 0;
    }

    /** @return array{requirement: string, variant: string|null, physical: bool, raw: string} */
    public static function normalizeControl(string $control, string $raw): array
    {
        $v = trim($raw);
        $l = mb_strtolower($v);
        $physical = in_array($control, self::PHYSICAL_CONTROLS, true);
        [$requirement, $variant] = match (true) {
            $l === 'no' => ['NOT_REQUIRED', null],
            $l === 'no public qr' => ['REQUIRED', 'NO_PUBLIC'],
            $l === 'yes' => ['REQUIRED', null],
            $l === 'yes private' => ['REQUIRED', 'PRIVATE'],
            $l === 'yes limited', $l === 'limited' => ['REQUIRED', 'LIMITED'],
            $l === 'light' => ['REQUIRED', 'LIGHT'],
            $l === 'dynamic' => ['REQUIRED', 'DYNAMIC'],
            $l === 'optional' => ['OPTIONAL', null],
            $l === 'optional/finance' => ['OPTIONAL', 'FINANCE'],
            $l === 'config' => ['CONFIGURABLE', null],
            $l === 'yes s5', $l === 'optional/yes s5' => [str_starts_with($l, 'optional') ? 'OPTIONAL' : 'REQUIRED', 'S5'],
            $l === 'yes s5/config', $l === 'config s5' => ['CONFIGURABLE', 'S5'],
            $l === 'yes for publish' => ['REQUIRED', 'PUBLISH'],
            $l === 'versioned' => ['REQUIRED', 'VERSIONED'],
            in_array($l, ['system', 'yes/system', 'system/yes'], true) => ['REQUIRED', 'SYSTEM'],
            $l === 'tokenized' => ['REQUIRED', 'TOKENIZED'],
            $l === 'cryptographic evidence' => ['REQUIRED', 'CRYPTOGRAPHIC'],
            // watermark status words (DUPLICATE, CANCELLED ...): a required status watermark (WM-STATUS).
            $control === 'watermark' && preg_match('/^[A-Z\/\- ]+$/', $v) === 1 => ['REQUIRED', 'STATUS:'.$v],
            default => ['PENDING_VERIFICATION', null],
        };

        return ['requirement' => $requirement, 'variant' => $variant, 'physical' => $physical, 'raw' => $v];
    }

    /**
     * @param  array<string, mixed>  $doc  one document_system.documents record
     * @return array<string, mixed> normalized profile
     */
    public static function profile(array $doc): array
    {
        $sp = $doc['security_profile'];
        $range = self::tierRange((string) ($sp['tier'] ?? $doc['typical_security_tier'] ?? 'S1'));
        $controls = [];
        foreach (self::CONTROLS as $c) {
            $controls[$c] = self::normalizeControl($c, (string) ($sp[$c] ?? 'PENDING_VERIFICATION'));
        }
        $classes = array_values(array_filter(array_map('trim', explode('/', (string) ($sp['confidentiality'] ?? '')))));
        usort($classes, fn ($a, $b) => (self::CONFIDENTIALITY_RANK[$b] ?? 9) <=> (self::CONFIDENTIALITY_RANK[$a] ?? 9));
        $access = array_values(array_filter(array_map('trim', explode('/', (string) ($sp['access'] ?? '')))));

        return [
            'tier_floor' => $range['floor'], 'tier_ceiling' => $range['ceiling'], 'controls' => $controls,
            'confidentiality_classes' => $classes, 'confidentiality_class' => $classes[0] ?? 'CUSTOMER_PRIVATE',
            'access_profiles' => $access,
        ];
    }

    /**
     * Detailed field map of a critical document: every bullet with its target and whether it is enforced.
     *
     * @return array<int, array{bullet: string, section: string, target: mixed, conditional: bool, status: string}>
     */
    public static function detailedFieldMap(?array $detailed): array
    {
        $out = [];
        $sections = (array) ($detailed['sections'] ?? []);
        $sectioned = array_sum(array_map(fn ($s) => count((array) ($s['items'] ?? [])), $sections));
        if ($sectioned === 0) {
            // Flat "Must include:" specs keep their bullets only in all_bullets.
            $sections = ['Must include' => ['items' => (array) ($detailed['all_bullets'] ?? [])]];
        }
        foreach ($sections as $section => $s) {
            foreach ((array) ($s['items'] ?? []) as $bullet) {
                $c = CanonicalFieldDictionary::classify((string) $bullet);
                $target = $c['target'];
                $status = match (true) {
                    $target === null => 'UNMAPPED_PENDING_VERIFICATION',
                    $target === '@no_source' => 'NO_CANONICAL_SOURCE',
                    is_string($target) && str_starts_with($target, '@control:') => 'SECURITY_CONTROL',
                    $c['conditional'] => 'CONDITIONAL',
                    default => 'ENFORCED',
                };
                $out[] = ['bullet' => trim((string) $bullet, " ;."), 'section' => (string) $section, 'target' => $target, 'conditional' => $c['conditional'], 'status' => $status];
            }
        }

        return $out;
    }

    /** Normalized comparable name for the catalogue mapping (exact-name match only; no fuzzy guess). */
    public static function nameKey(string $name): string
    {
        $name = strtr(mb_strtolower($name), ['’' => '', "'" => '', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'ç' => 'c', 'ô' => 'o', 'î' => 'i', 'û' => 'u', 'ù' => 'u', 'ï' => 'i']);

        return (string) preg_replace('/[^a-z0-9]/', '', $name);
    }
}
