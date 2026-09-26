<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace;

use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\DataReadiness\DataStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gap closure pack 04 (health provider / service / tariff master) — production gates in the Data Readiness registry
 * (domain "health"). Statuses are COMPUTED from the canonical tables:
 *  - provider types / specialties / service families: PLATFORM_NORMALIZED vocabularies (seeded master data);
 *  - provider_master: PENDING_OFFICIAL_IMPORT until the MINSANTE list is imported (import target health_provider_master),
 *    then UNVERIFIED until every imported row is verified;
 *  - service_catalogue: CONFIG_REQUIRED until services with a family exist (import target medical_services);
 *  - provider_tariffs: PENDING_PRIVATE_SOURCE until an insurer has an APPROVED (maker-checker) tariff version. No tariff
 *    value is ever invented: without one, the claim pricer rejects the line NO_CONTRACTED_TARIFF and episode billing
 *    reports NO_TARIFF_REVIEW_REQUIRED.
 */
final class ProviderDataGates
{
    public const SOURCE = 'GAP_CLOSURE_PACK_04';

    /** service family => medical_service_category (same mapping as the seeded master data attributes). */
    public const FAMILY_CATEGORY = [
        'CONSULTATION' => 'CONSULTATION', 'EMERGENCY' => 'EMERGENCY_VISIT', 'INPATIENT' => 'HOSPITAL_DAY', 'ICU' => 'ICU_DAY', 'SURGERY' => 'SURGERY',
        'MATERNITY' => 'MATERNITY_SERVICE', 'DELIVERY' => 'MATERNITY_SERVICE', 'LABORATORY' => 'LAB_TEST', 'PATHOLOGY' => 'LAB_TEST', 'IMAGING_XRAY' => 'IMAGING',
        'IMAGING_ULTRASOUND' => 'IMAGING', 'IMAGING_CT' => 'IMAGING', 'IMAGING_MRI' => 'IMAGING', 'PHARMACY' => 'PHARMACY_ITEM', 'DENTAL' => 'DENTAL_SERVICE',
        'OPTICAL' => 'OPTICAL_SERVICE', 'PHYSIOTHERAPY' => 'PHYSIOTHERAPY', 'AMBULANCE' => 'AMBULANCE', 'PREVENTIVE' => 'OTHER', 'VACCINATION' => 'OTHER',
        'CHRONIC_CARE' => 'OTHER', 'DIALYSIS' => 'OTHER', 'ONCOLOGY' => 'OTHER', 'MENTAL_HEALTH' => 'OTHER',
    ];

    /** @return list<string> */
    public static function serviceFamilies(): array
    {
        return array_keys(self::FAMILY_CATEGORY);
    }

    public static function register(): void
    {
        DataReadinessRegistry::extend('health', fn (array $section): array => app(self::class)->rows());
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        $reg = app(DataReadinessRegistry::class);
        $rows = [];
        foreach (['provider_type' => 'gap04.provider_types', 'medical_specialty' => 'gap04.specialties', 'service_family' => 'gap04.service_families'] as $list => $item) {
            $n = $this->has('master_data_values') ? DB::table('master_data_values')->where(['domain_code' => 'provider', 'list_code' => $list])->count() : 0;
            $rows[] = $reg->row('health', $item, 'PLATFORM_NORMALIZED', $n > 0 ? DataStatus::PLATFORM_NORMALIZED : DataStatus::PENDING_SOURCE,
                $n > 0 ? [] : ["master data provider.{$list} not seeded"], "{$n} values in provider.{$list}", self::SOURCE);
        }
        $imported = $this->has('provider_profiles') ? DB::table('provider_profiles')->where('data_source', 'GAP_CLOSURE_PACK_04_IMPORT')->count() : 0;
        $unverified = $imported ? DB::table('provider_profiles')->where('data_source', 'GAP_CLOSURE_PACK_04_IMPORT')->where('data_status', '<>', 'VERIFIED_PUBLIC_SOURCE')->count() : 0;
        $rows[] = $reg->row('health', 'gap04.provider_master', 'PENDING_OFFICIAL_IMPORT', $imported === 0 ? DataStatus::PENDING_SOURCE : ($unverified ? DataStatus::UNVERIFIED : DataStatus::VERIFIED),
            $imported === 0 ? ['Official MINSANTE facility list not imported (import target health_provider_master)'] : ($unverified ? ["{$unverified} imported providers pending verification"] : []),
            "{$imported} providers imported from the official source", self::SOURCE);
        $services = $this->has('medical_services') ? DB::table('medical_services')->whereNotNull('service_family')->count() : 0;
        $rows[] = $reg->row('health', 'gap04.service_catalogue', 'PLATFORM_NORMALIZED_WITH_LOCAL_CODE_MAPPING_REQUIRED', $services ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
            $services ? [] : ['No medical service with a service family: import the catalogue (import target medical_services) and map provider codes'], "{$services} catalogued services", self::SOURCE);
        $tariffs = $this->has('provider_tariff_versions') ? DB::table('provider_tariff_versions')->where('status', 'APPROVED')->count() : 0;
        $rows[] = $reg->row('health', 'gap04.provider_tariffs', 'PENDING_PRIVATE_SOURCE', $tariffs ? DataStatus::VERIFIED : DataStatus::PENDING_SOURCE,
            $tariffs ? [] : ['No approved negotiated tariff: load the signed provider tariff schedules per insurer (maker-checker)'], "{$tariffs} approved tariff versions", self::SOURCE);

        return $rows;
    }

    private function has(string $table): bool
    {
        return Schema::hasTable($table);
    }
}
