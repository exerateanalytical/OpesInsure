<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Import;

use App\Application\Import\ImportTarget;
use App\Application\Providers\ProviderRegistry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Gap closure pack 04 — provider_master (PENDING_OFFICIAL_IMPORT). Loads the official MINSANTE facility list (or a
 * network enrichment file) through the generic ImportPipeline (upload → map → validate → maker-checker approval).
 * Each row becomes a canonical provider (ProviderRegistry::register, credentialing PROSPECT) carrying the pack fields
 * with data_status = PENDING_VERIFICATION and its source_url: nothing is credentialed or contracted by an import.
 * Duplicates (same official name + city, or same licence reference) are never re-created.
 */
final class ProviderMasterImportTarget implements ImportTarget
{
    public const TYPES = ['GENERAL_HOSPITAL', 'CENTRAL_HOSPITAL', 'REGIONAL_HOSPITAL', 'DISTRICT_HOSPITAL', 'CMA', 'CSI', 'PRIVATE_HOSPITAL', 'PRIVATE_CLINIC', 'MEDICAL_CABINET',
        'PHARMACY', 'LABORATORY', 'IMAGING_CENTRE', 'DENTAL_CLINIC', 'OPTICAL_CENTRE', 'PHYSIOTHERAPY', 'AMBULANCE', 'SPECIALIST_PRACTICE',
        'HOSPITAL', 'CLINIC', 'MEDICAL_CENTRE', 'HEALTH_CENTRE', 'DENTAL', 'OPTICAL', 'AMBULANCE_PROVIDER', 'INDIVIDUAL_PRACTITIONER', 'OTHER'];

    public function __construct(private readonly ProviderRegistry $registry) {}

    public function key(): string
    {
        return 'health_provider_master';
    }

    public function label(): string
    {
        return 'Health provider master (MINSANTE / network enrichment)';
    }

    public function fields(): array
    {
        return ['official_name' => true, 'provider_type' => true, 'ownership_type' => false, 'category' => false, 'region' => false, 'health_district' => false,
            'health_area' => false, 'city' => false, 'address' => false, 'latitude' => false, 'longitude' => false, 'phones' => false, 'emails' => false,
            'license_or_authorization_reference' => false, 'source_url' => true, 'effective_from' => false];
    }

    public function params(array $params): array
    {
        return [];
    }

    public function check(array $row, array $params, array &$seen): array
    {
        $name = trim((string) ($row['official_name'] ?? ''));
        $type = strtoupper(trim((string) ($row['provider_type'] ?? '')));
        if ($name === '') {
            return ['status' => 'ERROR', 'error' => 'official_name is required'];
        }
        if (! in_array($type, self::TYPES, true)) {
            return ['status' => 'ERROR', 'error' => "Unknown provider_type {$type}"];
        }
        if (trim((string) ($row['source_url'] ?? '')) === '') {
            return ['status' => 'ERROR', 'error' => 'source_url (provenance) is required'];
        }
        foreach (['latitude' => 90, 'longitude' => 180] as $k => $max) {
            if (($row[$k] ?? '') !== '' && (! is_numeric($row[$k]) || abs((float) $row[$k]) > $max)) {
                return ['status' => 'ERROR', 'error' => "Invalid {$k}"];
            }
        }
        $key = mb_strtolower($name.'|'.($row['city'] ?? ''));
        if (isset($seen[$key])) {
            return ['status' => 'ERROR', 'error' => "{$name} repeated in the file"];
        }
        $seen[$key] = true;
        $licence = trim((string) ($row['license_or_authorization_reference'] ?? ''));
        $dupe = DB::table('provider_profiles')->where(fn ($q) => $q->whereRaw('LOWER(official_name) = ?', [mb_strtolower($name)])->whereRaw("COALESCE(details->>'city','') = ?", [(string) ($row['city'] ?? '')]))
            ->when($licence !== '', fn ($q) => $q->orWhere('license_or_authorization_reference', $licence))->value('id');

        return $dupe ? ['status' => 'DUPLICATE', 'key' => $key, 'matches' => (string) $dupe] : ['status' => 'NEW', 'key' => $key];
    }

    public function import(array $row, array $params, ?User $actor, string $batchId): string
    {
        $split = fn ($v) => array_values(array_filter(array_map('trim', preg_split('/[;,]/', (string) $v) ?: [])));
        $p = $this->registry->register(['category' => 'HEALTH', 'name' => trim((string) $row['official_name']), 'provider_type_code' => strtoupper((string) $row['provider_type']),
            'region_code' => ($row['region'] ?? '') ?: null, 'details' => ['city' => $row['city'] ?? null, 'address' => $row['address'] ?? null, 'import_batch_id' => $batchId]], $actor?->id);
        DB::table('provider_profiles')->where('id', $p->id)->update([
            'official_name' => trim((string) $row['official_name']), 'ownership_type' => ($row['ownership_type'] ?? '') ?: null, 'provider_category' => ($row['category'] ?? '') ?: null,
            'health_district' => ($row['health_district'] ?? '') ?: null, 'health_area' => ($row['health_area'] ?? '') ?: null,
            'latitude' => ($row['latitude'] ?? '') === '' ? null : (float) $row['latitude'], 'longitude' => ($row['longitude'] ?? '') === '' ? null : (float) $row['longitude'],
            'phones' => json_encode($split($row['phones'] ?? '')), 'emails' => json_encode($split($row['emails'] ?? '')),
            'license_or_authorization_reference' => ($row['license_or_authorization_reference'] ?? '') ?: null, 'effective_from' => ($row['effective_from'] ?? '') ?: null,
            'source_url' => $row['source_url'], 'data_status' => 'PENDING_VERIFICATION', 'data_source' => 'GAP_CLOSURE_PACK_04_IMPORT', 'updated_at' => now(),
        ]);

        return $p->id;
    }

    public function finish(array $params): void {}
}
