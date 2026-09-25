<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Carrier;
use App\Models\Directory\InstitutionOffice;
use App\Models\Directory\InstitutionProfile;
use App\Models\Directory\InstitutionVerificationLabel;
use App\Models\SeedCatalogVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Institutional directory of the 29 official Cameroon insurers (owner-supplied,
 * database/data/cameroon_insurers_directory_2026.json): contacts, website, PO box,
 * head office, direct branches, sources and verification status.
 *
 * - Enriches the existing official register carriers only; it NEVER creates a
 *   carrier, never deletes and never renames (display names stay the DGTCFM
 *   register names, so organization_name_histories is untouched).
 * - Matching: canonical ID (CM-INS-<BRANCH>-<NNN>) with the same licence branch,
 *   then normalized name / alias within the same branch. Ambiguity aborts.
 * - regulatory_reference is kept as a pending evidence note; it never creates
 *   an authorization (owner rule: authorizations need an approved evidence workflow).
 * - Records edited by an admin (admin_edited_at) are skipped; labels are seeded once.
 * - Nulls stay null. Idempotent (upserts keyed on carrier + office name).
 *
 * Entry point: php artisan opesinsure:seed-insurer-directory (hooked into optimize).
 */
final class CameroonInsurerDirectorySeeder extends Seeder
{
    public const DATASET = 'CM_INSURER_DIRECTORY';

    public const FILE = 'data/cameroon_insurers_directory_2026.json';

    private const NOISE = ['ASSURANCES', 'ASSURANCE', 'INSURANCE', 'CAMEROUN', 'CAMEROON', 'COMPAGNIE', 'CIE', 'AU', 'DU', 'SA', 'SARL'];

    /** @var array{matched: int, offices: int, addresses: int, unmatched: list<string>, admin_edited: list<string>} */
    public array $report = ['matched' => 0, 'offices' => 0, 'addresses' => 0, 'unmatched' => [], 'admin_edited' => []];

    public function run(): void
    {
        $data = self::directory();
        $version = (string) ($data['dataset']['version'] ?? '1');
        $verifiedAt = $data['dataset']['verified_at'] ?? null;

        DB::transaction(function () use ($data, $version, $verifiedAt): void {
            // Default labels; admin edits are never overwritten.
            foreach (InstitutionVerificationLabel::DEFAULTS as $code => [$en, $fr]) {
                DB::table('institution_verification_labels')->insertOrIgnore(['code' => $code, 'label_en' => $en, 'label_fr' => $fr, 'created_at' => now(), 'updated_at' => now()]);
            }

            $used = [];
            foreach ($data['insurers'] as $row) {
                $carrier = $this->match($row);
                if (! $carrier) {
                    $this->report['unmatched'][] = $row['id'].' '.$row['name'];

                    continue;
                }
                if (isset($used[$carrier->id])) {
                    throw new RuntimeException("Directory entries {$used[$carrier->id]} and {$row['id']} match the same carrier; refusing to seed.");
                }
                $used[$carrier->id] = $row['id'];
                if (InstitutionProfile::where('carrier_id', $carrier->id)->whereNotNull('admin_edited_at')->exists()) {
                    // An admin owns this record now; the file never overwrites it.
                    $this->report['admin_edited'][] = $row['id'];
                    $this->report['matched']++;

                    continue;
                }
                $this->apply($carrier, $row, $version, $verifiedAt);
                $this->report['matched']++;
            }

            SeedCatalogVersion::updateOrCreate(['dataset' => self::DATASET, 'version' => $version], [
                'effective_date' => $verifiedAt ?? now()->toDateString(), 'source' => 'OWNER_SUPPLIED', 'status' => 'ACTIVE',
                'summary' => ['insurers' => count($data['insurers']), 'matched' => $this->report['matched'], 'offices' => $this->report['offices']],
            ]);
        });
    }

    /** @return array{dataset: array<string, mixed>, insurers: list<array<string, mixed>>} */
    public static function directory(): array
    {
        $data = json_decode((string) file_get_contents(database_path(self::FILE)), true, 512, JSON_THROW_ON_ERROR);
        if (count($data['insurers'] ?? []) !== 29) {
            throw new RuntimeException('Insurer directory file is incomplete; refusing to seed.');
        }

        return $data;
    }

    /** @param array<string, mixed> $row */
    private function match(array $row): ?Carrier
    {
        $branch = $row['type'] ?? null;
        $official = Carrier::query()->where('is_official_register', true)->where('licence_branch', $branch);

        if ($byId = (clone $official)->where('canonical_id', $row['id'])->first()) {
            return $byId;
        }

        preg_match('/\(([^)]+)\)/u', (string) $row['name'], $m);
        $targets = array_filter([self::normalize($row['name']), self::normalize(preg_replace('/\(.*?\)/u', '', $row['name'])), self::normalize($m[1] ?? null), self::alias($row['name'])]);
        $hits = $official->get()->filter(fn (Carrier $c) => array_intersect($targets, array_filter([
            self::normalize($c->trade_name), self::normalize($c->legal_name), self::normalize($c->short_name), self::alias($c->trade_name),
        ])) !== []);

        return $hits->count() === 1 ? $hits->first() : null;
    }

    /** @param array<string, mixed> $row */
    private function apply(Carrier $carrier, array $row, string $version, ?string $verifiedAt): void
    {
        $status = $row['verification_status'] ?? null;
        if ($status !== null && ! in_array($status, InstitutionProfile::STATUSES, true)) {
            throw new RuntimeException("Unknown verification_status {$status} for {$row['id']}.");
        }
        $reference = filled($row['regulatory_reference'] ?? null) ? (string) $row['regulatory_reference'] : null;

        InstitutionProfile::updateOrCreate(['carrier_id' => $carrier->id], [
            'directory_id' => $row['id'],
            'directory_name' => $row['name'] ?? null,
            'website' => $row['website'] ?? null,
            'po_box' => $row['hq']['po_box'] ?? null,
            'phones' => array_values(array_filter($row['phones'] ?? [], 'filled')),
            'emails' => array_values(array_map('mb_strtolower', array_filter($row['emails'] ?? [], 'filled'))),
            'sources' => array_values($row['sources'] ?? []),
            'maps_listing' => $row['maps_business'] ?? null,
            'verification_status' => $status,
            'verified_at' => $verifiedAt,
            'regulatory_reference_note' => $reference,
            'regulatory_reference_status' => $reference ? 'PENDING_EVIDENCE_REVIEW' : null,
            'dataset' => self::DATASET,
            'dataset_version' => $version,
        ]);

        $this->headOfficeAddress($carrier, $row['hq'] ?? []);

        foreach (array_values($row['branches'] ?? []) as $i => $b) {
            $type = $b['type'] ?? null;
            if (! in_array($type, InstitutionOffice::TYPES, true) || blank($b['name'] ?? null)) {
                throw new RuntimeException("Invalid branch entry for {$row['id']}.");
            }
            InstitutionOffice::updateOrCreate(['carrier_id' => $carrier->id, 'name' => $b['name']], [
                'office_type' => $type, 'city' => $b['city'] ?? null, 'address' => $b['address'] ?? null,
                'phone' => $b['phone'] ?? null, 'sort_order' => $i + 1, 'source' => self::DATASET,
            ]);
            $this->report['offices']++;
        }
    }

    /** Head-office address goes to the canonical party_addresses table (type HEAD_OFFICE). */
    private function headOfficeAddress(Carrier $carrier, array $hq): void
    {
        if (! $carrier->party_id || blank($hq['city'] ?? null)) {
            return;
        }
        $existing = DB::table('party_addresses')->where('party_id', $carrier->party_id)->where('type', 'HEAD_OFFICE')->first();
        $values = ['city' => $hq['city'], 'line1' => $hq['address'] ?? null, 'country_code' => 'CM', 'updated_at' => now()];

        if ($existing) {
            DB::table('party_addresses')->where('id', $existing->id)->update($values);
        } else {
            $hasPrimary = DB::table('party_addresses')->where('party_id', $carrier->party_id)->where('is_primary', true)->exists();
            DB::table('party_addresses')->insert($values + [
                'id' => (string) Str::uuid(), 'party_id' => $carrier->party_id, 'type' => 'HEAD_OFFICE', 'is_primary' => ! $hasPrimary, 'created_at' => now(),
            ]);
        }
        $this->report['addresses']++;
    }

    private static function normalize(?string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii((string) $value))) ?? '';
    }

    private static function alias(?string $value): string
    {
        $words = preg_split('/[^A-Z0-9]+/', Str::upper(Str::ascii(preg_replace('/\(.*?\)/u', '', (string) $value) ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_diff($words, self::NOISE));
    }
}
