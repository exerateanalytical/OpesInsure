<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Import;

use App\Application\Import\ImportTarget;
use App\Application\Providers\ProviderNetworkService;
use App\Application\Providers\Workspace\ProviderDataGates;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Gap closure pack 04 — service_catalogue (PLATFORM_NORMALIZED structure, local code mapping required). Loads medical
 * services with the pack fields through the generic ImportPipeline; codes are created by ProviderNetworkService and
 * never overwritten. preauthorization_required_default stays NULL unless the file states it (never invented).
 */
final class MedicalServiceImportTarget implements ImportTarget
{
    public function __construct(private readonly ProviderNetworkService $network) {}

    public function key(): string
    {
        return 'medical_services';
    }

    public function label(): string
    {
        return 'Medical service catalogue';
    }

    public function fields(): array
    {
        return ['service_code' => true, 'name_en' => true, 'name_fr' => false, 'service_family' => true, 'category_code' => false, 'specialty' => false, 'unit' => false,
            'preauthorization_required_default' => false, 'inpatient_outpatient' => false, 'effective_from' => false, 'effective_until' => false, 'external_standard_code' => false];
    }

    public function params(array $params): array
    {
        return [];
    }

    public function check(array $row, array $params, array &$seen): array
    {
        $code = strtoupper(trim((string) ($row['service_code'] ?? '')));
        $family = strtoupper(trim((string) ($row['service_family'] ?? '')));
        if ($code === '' || trim((string) ($row['name_en'] ?? '')) === '') {
            return ['status' => 'ERROR', 'error' => 'service_code and name_en are required'];
        }
        if (! in_array($family, ProviderDataGates::serviceFamilies(), true)) {
            return ['status' => 'ERROR', 'error' => "Unknown service_family {$family}"];
        }
        if (isset($seen[$code])) {
            return ['status' => 'ERROR', 'error' => "{$code} repeated in the file"];
        }
        $seen[$code] = true;
        $dupe = DB::table('medical_services')->where('code', $code)->value('id');

        return $dupe ? ['status' => 'DUPLICATE', 'key' => $code, 'matches' => (string) $dupe] : ['status' => 'NEW', 'key' => $code];
    }

    public function import(array $row, array $params, ?User $actor, string $batchId): string
    {
        $family = strtoupper(trim((string) $row['service_family']));
        $category = ($row['category_code'] ?? '') ?: (ProviderDataGates::FAMILY_CATEGORY[$family] ?? 'OTHER');
        $s = $this->network->addMedicalService(['code' => $row['service_code'], 'name' => $row['name_en'], 'category_code' => $category]);
        $pre = (string) ($row['preauthorization_required_default'] ?? '');
        DB::table('medical_services')->where('id', $s->id)->update([
            'name_fr' => ($row['name_fr'] ?? '') ?: null, 'service_family' => $family, 'specialty_code' => ($row['specialty'] ?? '') ? strtoupper((string) $row['specialty']) : null,
            'unit' => ($row['unit'] ?? '') ?: null, 'preauthorization_required_default' => $pre === '' ? null : filter_var($pre, FILTER_VALIDATE_BOOLEAN),
            'inpatient_outpatient' => ($row['inpatient_outpatient'] ?? '') ? strtoupper((string) $row['inpatient_outpatient']) : null,
            'effective_from' => ($row['effective_from'] ?? '') ?: null, 'effective_until' => ($row['effective_until'] ?? '') ?: null,
            'external_standard_code' => ($row['external_standard_code'] ?? '') ?: null, 'data_status' => 'PLATFORM_NORMALIZED', 'data_source' => 'GAP_CLOSURE_PACK_04_IMPORT', 'updated_at' => now(),
        ]);

        return $s->id;
    }

    public function finish(array $params): void {}
}
