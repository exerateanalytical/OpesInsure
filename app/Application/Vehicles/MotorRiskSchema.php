<?php

declare(strict_types=1);

namespace App\Application\Vehicles;

use App\Models\InsuranceLine;
use Database\Seeders\VehicleMasterDataSeeder;

/**
 * The MOTOR section of RiskSchemaCatalogue (owned by the vehicle master).
 *
 * Make/model are VEHICLE_MAKE / VEHICLE_MODEL selectors backed by
 * /api/v1/public/vehicles/…; the chosen code is the fact (make_code,
 * model_code) and the display name is written to text_key (make, model) as
 * the snapshot. Options for enumerations come from the canonical master file.
 * Rating keys (registration_number, fiscal_power, usage_type, zone,
 * cover_type, claims_last_3_years) keep working: usage_type is derived from
 * vehicle_usage by VehicleUsageMapper. Make, model and country of origin are
 * data, never rating inputs.
 */
final class MotorRiskSchema
{
    public const MAKES_SOURCE = '/api/v1/public/vehicles/makes';

    public const MODELS_SOURCE = '/api/v1/public/vehicles/makes/{make_code}/models';

    public const MANUAL_ENTRY = '/api/v1/mobile/vehicles/master-review';

    public const EV_POWERTRAINS = ['PHEV', 'BEV'];

    public const HYBRID_POWERTRAINS = ['HYBRID', 'MILD_HYBRID', 'PHEV'];

    public const GOODS_USAGES = ['COMMERCIAL', 'GOODS_TRANSPORT', 'DELIVERY', 'COURIER', 'CONSTRUCTION', 'MINING', 'AGRICULTURE'];

    /** @var array<string, mixed>|null */
    private static ?array $enums = null;

    /** @return array{steps: array<int, array{key:string,label:string}>, fields: array<int, array<string, mixed>>, required: array<int, string>} */
    public static function schema(): array
    {
        $years = VehicleCatalogueService::modelYearRange();
        $yearOptions = array_map(fn (int $y) => ['value' => (string) $y, 'label' => (string) $y], range($years['max'], $years['min']));
        $ev = ['powertrain' => self::EV_POWERTRAINS];
        $goods = ['vehicle_usage' => self::GOODS_USAGES];

        $fields = [
            self::f('make_code', 'Make', 'VEHICLE_MAKE', 'vehicle', true, null, ['source' => self::MAKES_SOURCE, 'text_key' => 'make', 'manual_entry' => self::MANUAL_ENTRY]),
            self::f('model_code', 'Model', 'VEHICLE_MODEL', 'vehicle', true, null, ['source' => self::MODELS_SOURCE, 'depends_on' => 'make_code', 'text_key' => 'model', 'manual_entry' => self::MANUAL_ENTRY]),
            self::f('year', 'Model year', 'select', 'vehicle', true, $yearOptions),
            self::f('body_type', 'Body type', 'select', 'vehicle', true, self::options('body_types')),
            self::f('powertrain', 'Fuel / powertrain', 'select', 'vehicle', true, self::options('powertrains')),
            self::f('hybrid_subtype', 'Hybrid type', 'select', 'vehicle', false, self::options('hybrid_subtypes'), ['visible_when' => ['powertrain' => self::HYBRID_POWERTRAINS]]),
            self::f('transmission', 'Transmission', 'select', 'vehicle', false, self::options('transmissions')),
            // Optional picker steps (generation -> engine variant); the variant auto-fills the specs below.
            self::f('vehicle_generation_code', 'Generation', 'VEHICLE_GENERATION', 'vehicle', false, null, ['source' => '/api/v1/public/vehicles/models/{model_code}/generations', 'depends_on' => 'model_code']),
            self::f('vehicle_variant_code', 'Engine / version', 'VEHICLE_VARIANT', 'vehicle', false, null, ['source' => '/api/v1/public/vehicles/models/{model_code}/generations/{vehicle_generation_code}/variants?year={year}', 'depends_on' => 'vehicle_generation_code']),
            self::f('engine_capacity_cc', 'Engine capacity (cc)', 'number', 'vehicle', false, null, ['min' => 1, 'max' => 30000]),
            self::f('power_hp', 'Power (hp)', 'number', 'vehicle', false, null, ['min' => 1, 'max' => 3000]),
            self::f('drive_type', 'Drive type', 'select', 'vehicle', false, self::options('drive_types')),
            self::f('registration_number', 'Registration number', 'text', 'vehicle', true, null, ['placeholder' => 'LT 000 AA']),
            self::f('vin', 'VIN / chassis number', 'text', 'vehicle', false, null, ['max_length' => 40]),
            self::f('fiscal_power', 'Fiscal power (CV)', 'number', 'vehicle', true, null, ['min' => 1, 'max' => 60]),
            self::f('vehicle_value', 'Declared value (XAF)', 'number', 'vehicle', true, null, ['min' => 0]),
            self::f('battery_capacity_kwh', 'Battery capacity (kWh)', 'number', 'vehicle', false, null, ['min' => 1, 'max' => 300, 'visible_when' => $ev]),
            self::f('electric_range_km', 'Electric range (km)', 'number', 'vehicle', false, null, ['min' => 1, 'max' => 2000, 'visible_when' => $ev]),
            self::f('battery_ownership', 'Battery', 'select', 'vehicle', false, [['value' => 'OWNED', 'label' => 'Owned'], ['value' => 'LEASED', 'label' => 'Leased']], ['visible_when' => $ev]),
            self::f('vehicle_usage', 'Usage', 'select', 'usage', true, self::options('usage_types')),
            self::f('vehicle_class', 'Vehicle class', 'select', 'usage', false, self::options('vehicle_classes')),
            self::f('gross_vehicle_weight_kg', 'Gross vehicle weight (kg)', 'number', 'usage', false, null, ['min' => 500, 'max' => 60000, 'visible_when' => $goods]),
            self::f('payload_kg', 'Payload (kg)', 'number', 'usage', false, null, ['min' => 0, 'max' => 50000, 'visible_when' => $goods]),
            self::f('cargo_type', 'Cargo carried', 'text', 'usage', false, null, ['visible_when' => $goods]),
            self::f('zone', 'City / zone', 'select', 'owner', true, [['value' => 'DOUALA', 'label' => 'Douala'], ['value' => 'YAOUNDE', 'label' => 'Yaoundé'], ['value' => 'OTHER_URBAN', 'label' => 'Other city'], ['value' => 'RURAL', 'label' => 'Rural area']]),
            self::f('cover_type', 'Cover', 'select', 'cover', true, [['value' => 'THIRD_PARTY', 'label' => 'Third party'], ['value' => 'THIRD_PARTY_FIRE_THEFT', 'label' => 'Third party, fire & theft'], ['value' => 'COMPREHENSIVE', 'label' => 'Comprehensive']]),
            self::f('previous_insurer', 'Previous insurer', 'text', 'history', false),
            self::f('claims_last_3_years', 'Claims in the last 3 years', 'number', 'history', true, null, ['min' => 0, 'max' => 20]),
        ];

        return [
            'steps' => array_map(fn ($s) => ['key' => $s[0], 'label' => $s[1]], [['vehicle', 'Vehicle'], ['usage', 'Usage'], ['owner', 'Owner & location'], ['cover', 'Cover'], ['history', 'History']]),
            'fields' => $fields,
            'required' => ['registration_number', 'fiscal_power', 'usage_type', 'zone'],
        ];
    }

    /**
     * Optional picker facts: accepted when absent, validated when present.
     * Generation/variant codes must exist, be active and belong to the chosen
     * model (and variant to generation).
     *
     * @param  array<string, mixed>  $facts
     */
    public static function validateVehicleFacts(array $facts): void
    {
        $errors = [];
        $modelCode = isset($facts['model_code']) && is_scalar($facts['model_code']) ? strtoupper((string) $facts['model_code']) : null;
        $model = $modelCode ? \App\Models\Vehicles\VehicleModel::where('code', $modelCode)->first() : null;
        $generation = null;
        if (! empty($facts['vehicle_generation_code'])) {
            $generation = is_scalar($facts['vehicle_generation_code']) ? \App\Models\Vehicles\VehicleGeneration::where('code', strtoupper((string) $facts['vehicle_generation_code']))->where('active', true)->first() : null;
            if (! $generation || ($model && $generation->model_id !== $model->id)) {
                $errors['risk_facts.vehicle_generation_code'] = 'Unknown vehicle generation for this model.';
            }
        }
        if (! empty($facts['vehicle_variant_code'])) {
            $variant = is_scalar($facts['vehicle_variant_code']) ? \App\Models\Vehicles\VehicleVariant::where('code', strtoupper((string) $facts['vehicle_variant_code']))->where('active', true)->first() : null;
            if (! $variant || ($model && $variant->model_id !== $model->id) || ($generation && $variant->generation_id !== $generation->id)) {
                $errors['risk_facts.vehicle_variant_code'] = 'Unknown engine variant for this vehicle.';
            }
        }
        foreach (['engine_capacity_cc' => 30000, 'power_hp' => 3000] as $k => $max) {
            if (isset($facts[$k]) && $facts[$k] !== '' && (! is_numeric($facts[$k]) || $facts[$k] < 1 || $facts[$k] > $max)) {
                $errors["risk_facts.$k"] = "$k must be between 1 and $max.";
            }
        }
        if (! empty($facts['drive_type']) && ! \App\Models\Vehicles\VehicleReferenceValue::where(['group' => 'drive_type', 'code' => $facts['drive_type']])->exists()) {
            $errors['risk_facts.drive_type'] = 'Unknown drive type.';
        }
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    /**
     * A MOTOR line whose stored wizard schema still asks for free-text make/model
     * (pre-master-data seeding) gets the selector schema; its "required" keys
     * and any other custom schema are left alone. Returns true when upgraded.
     */
    public static function upgradeStoredLine(): bool
    {
        $line = InsuranceLine::where('code', 'MOTOR')->first();
        $stored = $line?->risk_schema ?? [];
        $legacy = collect($stored['fields'] ?? [])->contains(fn ($f) => ($f['key'] ?? null) === 'make' && strtolower((string) ($f['type'] ?? '')) === 'text');
        if (! $line || ! $legacy) {
            return false;
        }
        $schema = self::schema();
        $line->update(['risk_schema' => ['required' => $stored['required'] ?? $schema['required'], 'steps' => $schema['steps'], 'fields' => $schema['fields']]]);

        return true;
    }

    /** @return array<int, array{value: string, label: string}> */
    private static function options(string $enumKey): array
    {
        self::$enums ??= json_decode((string) file_get_contents(database_path(VehicleMasterDataSeeder::DATA_FILE)), true, 512, JSON_THROW_ON_ERROR)['enums'];
        $group = VehicleReferenceLabels::GROUPS[$enumKey];

        $options = array_map(fn ($e) => is_array($e)
            ? ['value' => $e[0], 'label' => $e[1]]
            : ['value' => $e, 'label' => VehicleReferenceLabels::for($group, $e)[0]], self::$enums[$enumKey]);
        foreach (VehicleReferenceLabels::EXTRA_VALUES[$group] ?? [] as $code => [$en]) {
            $options[] = ['value' => $code, 'label' => $en];
        }

        return $options;
    }

    private static function f(string $key, string $label, string $type, string $step, bool $required, ?array $options = null, array $extra = []): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type, 'step' => $step, 'required' => $required, 'options' => $options] + $extra;
    }
}
