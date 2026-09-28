<?php

declare(strict_types=1);

namespace App\Application\Documents\Letterhead;

use App\Models\Carrier;
use App\Models\Letterhead\LetterheadAsset;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Owner-supplied logo pack for the 29 official Cameroon insurers (resources/insurer-logos: normalized WebP files +
 * manifest.json; originals and provenance in Cameroon_insurer_logos_29_with_sources/SOURCES.json).
 *
 * Each pack entry is mapped explicitly to the register carrier by canonical ID + insurer code (set by
 * CameroonInsuranceRegisterSeeder); nothing is matched by name at runtime. The pack numbering is NOT the register
 * sequence: pack 15 is SAAR (register IARD-016) and pack 16 is SanlamAllianz (register IARD-015).
 *
 * Every logo becomes a new letterhead version through LetterheadService (audited, owner rule: the version records
 * who authorized it, when and the basis). Idempotent: an artwork already imported for a carrier (same sha256, any
 * version) is never re-applied, and a logo the insurer or an admin uploaded is never overwritten unless forced.
 */
final class InsurerLogoPack
{
    public const BASIS = 'Owner-supplied logo pack 2026-09-28 (Cameroon_insurer_logos_29_with_sources)';

    public const AUTHORIZED_BY = 'Platform owner (OpesInsure)';

    public const AUTHORIZED_ON = '2026-09-28';

    /** Pack number => [carrier canonical_id, carrier insurer_code, pack branch, pack company]. */
    public const MAP = [
        1 => ['CM-INS-IARD-001', 'ACTIVA', 'non-life', 'ACTIVA Assurances'],
        2 => ['CM-INS-IARD-002', 'AFG', 'non-life', 'AFG Assurances'],
        3 => ['CM-INS-IARD-003', 'AFRI', 'non-life', 'AFRI Insurance'],
        4 => ['CM-INS-IARD-004', 'AREA', 'non-life', 'AREA Assurances'],
        5 => ['CM-INS-IARD-005', 'AGC', 'non-life', 'AGC Assurances'],
        6 => ['CM-INS-IARD-006', 'AXA', 'non-life', 'AXA Assurances Cameroun'],
        7 => ['CM-INS-IARD-007', 'BELIFE_GENERAL', 'non-life', 'Belife General Insurance'],
        8 => ['CM-INS-IARD-008', 'CHANAS', 'non-life', 'Chanas Assurances'],
        9 => ['CM-INS-IARD-009', 'CPA', 'non-life', 'CPA Assurances'],
        10 => ['CM-INS-IARD-010', 'GMC', 'non-life', 'GMC Assurances'],
        11 => ['CM-INS-IARD-011', 'LD', 'non-life', 'LD Assurances'],
        12 => ['CM-INS-IARD-012', 'NSIA_IARD', 'non-life', 'NSIA Assurances'],
        13 => ['CM-INS-IARD-013', 'PROASSUR', 'non-life', 'Pro Assur'],
        14 => ['CM-INS-IARD-014', 'ROYAL_ONYX', 'non-life', 'Royal Onyx Insurance'],
        15 => ['CM-INS-IARD-016', 'SAAR', 'non-life', 'SAAR Assurances'],
        16 => ['CM-INS-IARD-015', 'SANLAM_ALLIANZ_IARD', 'non-life', 'SanlamAllianz Cameroun Assurances'],
        17 => ['CM-INS-IARD-017', 'SUNU_IARD', 'non-life', 'SUNU Assurances'],
        18 => ['CM-INS-IARD-018', 'ZENITHE', 'non-life', 'Zenithe Insurance'],
        19 => ['CM-INS-LIFE-001', 'ACAM_VIE', 'life', 'ACAM Vie'],
        20 => ['CM-INS-LIFE-002', 'ACTIVA_VIE', 'life', 'ACTIVA Vie'],
        21 => ['CM-INS-LIFE-003', 'AFRILIFE', 'life', 'AFRILIFE Insurance Cameroun'],
        22 => ['CM-INS-LIFE-004', 'BELIFE', 'life', 'Belife Insurance'],
        23 => ['CM-INS-LIFE-005', 'CHANAS_VIE', 'life', 'Chanas Assurances Vie'],
        24 => ['CM-INS-LIFE-006', 'NSIA_VIE', 'life', 'NSIA Vie Assurances'],
        25 => ['CM-INS-LIFE-007', 'SAAR_VIE', 'life', 'SAAR Vie'],
        26 => ['CM-INS-LIFE-008', 'SANLAM_ALLIANZ_VIE', 'life', 'SanlamAllianz Cameroun Assurances Vie'],
        27 => ['CM-INS-LIFE-009', 'SONAM_VIE', 'life', 'SONAM Vie'],
        28 => ['CM-INS-LIFE-010', 'SUNU_VIE', 'life', 'SUNU Assurances Vie'],
        29 => ['CM-INS-LIFE-011', 'WAFA_VIE', 'life', 'WAFA Vie'],
    ];

    public function __construct(private readonly LetterheadService $letterheads) {}

    public static function defaultPath(): string
    {
        return resource_path('insurer-logos');
    }

    /**
     * Action per entry: imported | would_import | unchanged | pending | skipped_owned | missing | unmatched | error.
     *
     * @return list<array{number: int, company: string, carrier: ?string, action: string, detail: ?string, version: ?int}>
     */
    public function import(?string $path = null, bool $dryRun = false, bool $force = false): array
    {
        $path = rtrim($path ?? self::defaultPath(), '/\\');
        $manifest = json_decode((string) @file_get_contents($path.'/manifest.json'), true);
        if (! is_array($manifest)) {
            throw new RuntimeException("No readable manifest.json in {$path}.");
        }

        $rows = [];
        foreach ($manifest as $entry) {
            $rows[] = $this->one($path, (array) $entry, $dryRun, $force);
        }
        foreach (array_diff(array_keys(self::MAP), array_column($rows, 'number')) as $n) {
            $rows[] = ['number' => $n, 'company' => self::MAP[$n][3], 'carrier' => null, 'action' => 'missing', 'detail' => 'Not in the pack manifest.', 'version' => null];
        }

        return $rows;
    }

    /** @param array<string, mixed> $e */
    private function one(string $path, array $e, bool $dryRun, bool $force): array
    {
        $n = (int) ($e['number'] ?? 0);
        $row = ['number' => $n, 'company' => (string) ($e['company'] ?? '?'), 'carrier' => null, 'action' => 'error', 'detail' => null, 'version' => null];
        $map = self::MAP[$n] ?? null;
        if (! $map || $map[2] !== ($e['branch'] ?? null) || $map[3] !== ($e['company'] ?? null)) {
            return ['action' => 'unmatched', 'detail' => 'Pack entry does not match the explicit mapping (number / branch / company).'] + $row;
        }
        [$canonicalId, $code] = $map;
        $carrier = Carrier::where('canonical_id', $canonicalId)->where('insurer_code', $code)->first();
        if (! $carrier) {
            return ['action' => 'unmatched', 'detail' => "No carrier {$canonicalId} / {$code} (run opesinsure:seed-regulatory first)."] + $row;
        }
        $row['carrier'] = $canonicalId.' '.$code;

        $file = $path.'/'.basename((string) ($e['file'] ?? ''));
        $bytes = is_file($file) ? (string) file_get_contents($file) : '';
        $sha = hash('sha256', $bytes);
        if ($bytes === '' || ! hash_equals((string) ($e['sha256'] ?? ''), $sha)) {
            return ['detail' => 'Missing file or sha256 differs from the manifest.'] + $row;
        }

        $versions = LetterheadAsset::where('owner_type', 'CARRIER')->where('carrier_id', $carrier->id);
        $current = LetterheadResolver::current('CARRIER', $carrier->id);
        if (! $force) {
            $seen = (clone $versions)->where('logo_sha256', $sha)->where('authorization_source', self::BASIS)->orderByDesc('version')->first();
            if ($seen) {
                return ['action' => $seen->status === 'PENDING_APPROVAL' ? 'pending' : 'unchanged', 'version' => $seen->version,
                    'detail' => $seen->status === 'PENDING_APPROVAL' ? 'Awaiting letterhead approval (maker-checker).' : null] + $row;
            }
            if ($current?->logo_path && $current->authorization_source !== self::BASIS) {
                return ['action' => 'skipped_owned', 'version' => $current->version,
                    'detail' => 'Keeps its own '.($current->public_display ? 'public' : 'private').' logo (authorized by '.$current->authorized_by.'); use --force to replace.'] + $row;
            }
        }

        try {
            $this->letterheads->inspect($bytes, 'logo');
        } catch (ValidationException $ex) {
            return ['detail' => Arr::first(Arr::flatten($ex->errors()))] + $row;
        }
        if ($dryRun) {
            return ['action' => 'would_import'] + $row;
        }

        // A new version carries the current letterhead's text (address, RCCM, footer...) forward; only the logo,
        // its public display and its authorization change.
        $data = ($current ? $current->only(LetterheadService::TEXT_FIELDS) : []);
        $data = array_merge($data, [
            'public_display' => true,
            'authorized_by' => self::AUTHORIZED_BY,
            'authorized_on' => min(self::AUTHORIZED_ON, now()->toDateString()),
            'authorization_source' => self::BASIS,
            'authorization_note' => sprintf('Pack entry %02d (%s, %s). Source: %s (%s). Logos are third-party trademarks; confirm production-use artwork with the insurer.',
                $n, $e['company'], $e['branch'], $e['source'] ?? 'n/a', $e['asset_status'] ?? 'n/a'),
        ]);
        $asset = $this->letterheads->publish('CARRIER', $carrier->id, $data, $bytes, null, null);

        return ['action' => 'imported', 'version' => $asset->version, 'detail' => $asset->status === 'ACTIVE' ? null : 'Awaiting letterhead approval (maker-checker).'] + $row;
    }
}
