<?php

declare(strict_types=1);

namespace App\Application\Vehicles;

use App\Models\Vehicles\VehicleGeneration;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleMasterChange;
use App\Models\Vehicles\VehicleMasterSource;
use App\Models\Vehicles\VehicleModel;
use App\Models\Vehicles\VehicleVariant;
use Database\Seeders\VehicleMasterDataSeeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Imports generations + engine variants from the global dataset
 * (github.com/gor3a/vehicle-makes-models, data under ODbL v1.0, upstream
 * autoevolution.com) from a LOCAL file: data/csv/engines.csv or a tier-3
 * JSON file (<make>.json / all.json). Never downloads, never executes code.
 *
 * Mode FILTER_BY_CORE_MAKES_AND_MODELS: only makes in the Cameroon & Africa
 * config (vehicle_master_config_africa_2026.json) and only models that already
 * exist in the vehicle master (by name or alias) are imported. It never
 * creates makes or models.
 *
 * Rows get provenance INDUSTRY_DATABASE / data_source GLOBAL_VEHICLE_DATASET.
 * Re-running is idempotent (external_ref). A row edited by an admin, or owned
 * by a higher-priority source, is never overwritten; its empty fields may be filled.
 */
final class VehicleDatasetImporter
{
    public const SOURCE_CODE = 'GLOBAL_DATASET_GOR3A';

    public const REF_PREFIX = 'gor3a:';

    public const DISABLED_MESSAGE = 'The global ODbL vehicle dataset importer is disabled by owner decision 20 (2026-09-25): the curated vehicle master is used instead. '
        .'Only the owner can enable it (VEHICLE_GLOBAL_DATASET_IMPORT_ENABLED=true). --dry-run remains available.';

    /** Owner decision 20: real imports are refused unless the owner-set flag exists (even with --accept-license). */
    public static function enabled(): bool
    {
        return (bool) config('vehicles.global_dataset_import_enabled', false);
    }

    /** @var array<string, int> */
    public array $counts = ['rows' => 0, 'skipped_make' => 0, 'skipped_model' => 0, 'generations_created' => 0, 'generations_updated' => 0, 'variants_created' => 0, 'variants_updated' => 0, 'protected' => 0];

    /** @var array<string, int> dataset "make|model" not found in the master (for the report) */
    public array $unmatchedModels = [];

    /** @var array<string, VehicleMake|false> */
    private array $makeCache = [];

    /** @var array<string, VehicleModel|false> */
    private array $modelCache = [];

    public function __construct(private readonly VehicleCatalogueService $catalogue) {}

    public function import(string $path, bool $dryRun = false): array
    {
        if (! $dryRun && ! self::enabled()) {
            throw new \RuntimeException(self::DISABLED_MESSAGE);
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("File not found: $path");
        }
        $rows = str_ends_with(strtolower($path), '.csv') ? $this->readCsv($path) : $this->readJson($path);
        $core = $this->coreMakeCodes();

        $run = function () use ($rows, $core, $path): void {
            foreach ($rows as $row) {
                $this->counts['rows']++;
                $this->importRow($row, $core);
            }
            $source = VehicleMasterSource::firstOrCreate(['code' => self::SOURCE_CODE], [
                'name' => 'vehicle-makes-models (github.com/gor3a/vehicle-makes-models), ODbL v1.0; upstream autoevolution.com',
                'provenance' => 'INDUSTRY_DATABASE',
            ]);
            $source->update(['content_hash' => hash_file('sha256', $path), 'imported_at' => now()]);
            VehicleMasterChange::create(['entity_type' => 'vehicle_master_source', 'entity_id' => $source->id, 'action' => 'DATASET_IMPORTED',
                'after' => $this->counts, 'reason' => 'opesinsure:import-vehicle-dataset '.basename($path), 'occurred_at' => now()]);
        };

        if ($dryRun) {
            DB::beginTransaction();
            try {
                $run();
            } finally {
                DB::rollBack();
            }
        } else {
            DB::transaction($run);
        }

        arsort($this->unmatchedModels);

        return $this->counts;
    }

    /** @return array<string, true> make codes in scope (config core + commercial lists, resolved against the master) */
    public function coreMakeCodes(): array
    {
        $path = database_path(VehicleMasterDataSeeder::AFRICA_CONFIG_FILE);
        $config = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $codes = [];
        foreach ([...($config['makes'] ?? []), ...($config['commercial_vehicle_makes'] ?? [])] as $row) {
            $name = is_array($row) ? $row['name'] : (string) $row;
            $make = (is_array($row) && isset($row['code']) ? VehicleMake::where('code', $row['code'])->first() : null) ?? $this->catalogue->resolveMake($name);
            if ($make) {
                $codes[$make->code] = true;
            }
        }

        return $codes;
    }

    /** @param array<string, mixed> $row normalized row */
    private function importRow(array $row, array $core): void
    {
        $make = $this->make((string) $row['make']);
        if (! $make || ! isset($core[$make->code])) {
            $this->counts['skipped_make']++;

            return;
        }
        $model = $this->model($make, (string) $row['model']);
        if (! $model) {
            $this->counts['skipped_model']++;
            $key = $make->code.'|'.$row['model'];
            $this->unmatchedModels[$key] = ($this->unmatchedModels[$key] ?? 0) + 1;

            return;
        }
        if (trim((string) $row['generation']) === '') {
            return;
        }

        $generation = $this->generation($model, $row);
        if ($generation && trim((string) ($row['engine_label'] ?? '')) !== '') {
            $this->variant($model, $generation, $row);
        }
    }

    private function generation(VehicleModel $model, array $row): VehicleGeneration
    {
        $name = mb_substr(trim((string) $row['generation']), 0, 250);
        $ref = self::REF_PREFIX.VehicleText::normalize($model->code).'|'.VehicleText::normalize($name);
        $attrs = ['year_from' => $this->year($row['gen_year_start'] ?? null), 'year_to' => $this->year($row['gen_year_end'] ?? null), 'body_type' => $this->bodyType($row['body_type'] ?? null)];

        $existing = VehicleGeneration::where('external_ref', $ref)->first()
            ?? VehicleGeneration::where('model_id', $model->id)->whereNull('external_ref')->get()->first(fn ($g) => VehicleText::normalize($g->name) === VehicleText::normalize($name));
        if (! $existing) {
            $code = $this->uniqueCode(VehicleGeneration::class, mb_substr($model->code.'_'.VehicleText::code($name), 0, 190));
            $this->counts['generations_created']++;

            return VehicleGeneration::create(['model_id' => $model->id, 'code' => $code, 'name' => $name, 'external_ref' => $ref,
                'provenance' => 'INDUSTRY_DATABASE', 'data_source' => VehicleDataSource::GLOBAL_DATASET, 'active' => true] + $attrs);
        }
        $this->merge($existing, $attrs + ['external_ref' => $ref], 'generations_updated');

        return $existing;
    }

    private function variant(VehicleModel $model, VehicleGeneration $generation, array $row): void
    {
        $label = mb_substr(trim((string) $row['engine_label']), 0, 250);
        $ref = $generation->external_ref.'|'.VehicleText::normalize($label);
        $hp = $this->int($row['power_hp'] ?? null, 3000);
        [$powertrain, $hybrid] = $this->powertrain($row['fuel_type'] ?? null);
        $attrs = [
            'powertrain' => $powertrain, 'hybrid_subtype' => $hybrid,
            'fuel_type_raw' => ($ft = trim((string) ($row['fuel_type'] ?? ''))) !== '' ? mb_substr($ft, 0, 60) : null,
            'transmission' => $this->transmission($row['transmission'] ?? null, $label),
            'drive_type' => $this->drivetrain($row['drivetrain'] ?? null),
            'body_type' => $this->bodyType($row['body_type'] ?? null) ?? $generation->body_type,
            'engine_capacity_cc' => $this->int($row['displacement_cc'] ?? null, 30000),
            'power_hp' => $hp, 'power_kw' => $hp ? round($hp * 0.7457, 2) : null,
            'torque_nm' => $this->int($row['torque_nm'] ?? null, 5000),
            'cylinders' => $this->int($row['cylinders'] ?? null, 24),
            'year_from' => $this->year($row['gen_year_start'] ?? null), 'year_to' => $this->year($row['gen_year_end'] ?? null),
            'specs' => isset($row['specs']) && is_array($row['specs']) && $row['specs'] !== [] ? array_slice($row['specs'], 0, 200, true) : null,
        ];

        $existing = VehicleVariant::where('external_ref', $ref)->first();
        if (! $existing) {
            $code = $this->uniqueCode(VehicleVariant::class, mb_substr($generation->code.'_'.VehicleText::code($label), 0, 230));
            VehicleVariant::create(['model_id' => $model->id, 'generation_id' => $generation->id, 'code' => $code, 'name' => $label, 'external_ref' => $ref,
                'provenance' => 'INDUSTRY_DATABASE', 'data_source' => VehicleDataSource::GLOBAL_DATASET, 'active' => true] + $attrs);
            $this->counts['variants_created']++;

            return;
        }
        $this->merge($existing, $attrs, 'variants_updated');
    }

    /** Overwrite only rows this source may own; otherwise fill empty fields only. */
    private function merge(VehicleGeneration|VehicleVariant $row, array $attrs, string $counter): void
    {
        $owns = $row->admin_modified_at === null && VehicleDataSource::mayOverwrite(VehicleDataSource::GLOBAL_DATASET, $row->data_source);
        if (! $owns) {
            $this->counts['protected']++;
        }
        foreach ($attrs as $k => $v) {
            if ($v !== null && ($owns || $row->{$k} === null || $row->{$k} === '')) {
                $row->{$k} = $v;
            }
        }
        if ($row->isDirty()) {
            $row->save();
            $this->counts[$counter]++;
        }
    }

    private function make(string $name): ?VehicleMake
    {
        $key = VehicleText::normalize($name);
        if (! array_key_exists($key, $this->makeCache)) {
            $make = $this->catalogue->resolveMake($name);
            if ($make?->merged_into_id) {
                $make = VehicleMake::find($make->merged_into_id);
            }
            $this->makeCache[$key] = $make ?? false;
        }

        return $this->makeCache[$key] ?: null;
    }

    private function model(VehicleMake $make, string $name): ?VehicleModel
    {
        $key = $make->id.'|'.VehicleText::normalize($name);
        if (! array_key_exists($key, $this->modelCache)) {
            $model = $this->catalogue->resolveModel($make, $name);
            $this->modelCache[$key] = $model && $model->active ? $model : false;
        }

        return $this->modelCache[$key] ?: null;
    }

    /** @return iterable<array<string, mixed>> */
    private function readCsv(string $path): iterable
    {
        $fh = fopen($path, 'rb');
        $header = fgetcsv($fh, escape: '\\');
        if (! $header || ! in_array('engine_label', $header, true) || ! in_array('generation', $header, true)) {
            fclose($fh);
            throw new InvalidArgumentException('CSV must be the dataset engines.csv (make, model, generation, …, engine_label, …).');
        }
        $header = array_map(fn ($h) => trim((string) $h, "\xEF\xBB\xBF \t"), $header);
        while (($line = fgetcsv($fh, escape: '\\')) !== false) {
            if (count($line) !== count($header)) {
                continue;
            }
            yield array_combine($header, array_map(fn ($v) => $v === '' ? null : $v, $line));
        }
        fclose($fh);
    }

    /** Tier-3 JSON: {group, makes:[{name, models:[{name, generations:[{name, yearStart, yearEnd, bodyType, engines:[…]}]}]}]} or an array of those. */
    private function readJson(string $path): iterable
    {
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $groups = array_is_list($data) ? $data : [$data];
        foreach ($groups as $group) {
            foreach ($group['makes'] ?? [] as $make) {
                foreach ($make['models'] ?? [] as $model) {
                    foreach ($model['generations'] ?? [] as $gen) {
                        $base = ['make' => $make['name'] ?? '', 'model' => $model['name'] ?? '', 'generation' => $gen['name'] ?? '',
                            'gen_year_start' => $gen['yearStart'] ?? null, 'gen_year_end' => $gen['yearEnd'] ?? null, 'body_type' => $gen['bodyType'] ?? null];
                        $engines = $gen['engines'] ?? [];
                        if ($engines === []) {
                            yield $base + ['engine_label' => null];
                        }
                        foreach ($engines as $e) {
                            yield $base + [
                                'engine_label' => $e['label'] ?? null, 'fuel_type' => $e['fuelType'] ?? null, 'cylinders' => $e['cylinders'] ?? null,
                                'displacement_cc' => $e['displacementCc'] ?? null, 'power_hp' => $e['powerHp'] ?? null, 'torque_nm' => $e['torqueNm'] ?? null,
                                'transmission' => $e['transmission'] ?? null, 'drivetrain' => $e['drivetrain'] ?? null, 'specs' => $e['specs'] ?? null,
                            ];
                        }
                    }
                }
            }
        }
    }

    /** @return array{0: ?string, 1: ?string} [powertrain code, hybrid subtype] */
    private function powertrain(mixed $fuel): array
    {
        $f = strtolower(trim((string) $fuel));

        return match (true) {
            $f === '' => [null, null],
            str_contains($f, 'plug') => ['PHEV', 'PHEV'],
            str_contains($f, 'mild') => ['MILD_HYBRID', 'MHEV'],
            str_contains($f, 'hybrid') => ['HYBRID', 'HEV'],
            str_contains($f, 'electric') => ['BEV', null],
            str_contains($f, 'hydrogen') || str_contains($f, 'fuel cell') => ['HYDROGEN', null],
            str_contains($f, 'lpg') => ['LPG', null],
            str_contains($f, 'cng') || str_contains($f, 'natural gas') => ['CNG', null],
            str_contains($f, 'diesel') => ['DIESEL', null],
            str_contains($f, 'gasoline') || str_contains($f, 'petrol') => ['PETROL', null],
            default => ['OTHER', null],
        };
    }

    private function transmission(mixed $value, string $label): ?string
    {
        $t = strtolower(trim((string) $value)) ?: strtolower($label);

        return match (true) {
            str_contains($t, 'cvt') => 'CVT',
            str_contains($t, 'dual') || str_contains($t, 'dct') || str_contains($t, 'dsg') => 'DCT',
            (bool) preg_match('/\bamt\b|robot/', $t) => 'AMT',
            str_contains($t, 'automatic') || (bool) preg_match('/\bat\b|\d+at\b/', $t) => 'AUTOMATIC',
            str_contains($t, 'manual') || (bool) preg_match('/\bmt\b|\d+mt\b/', $t) => 'MANUAL',
            default => null,
        };
    }

    private function drivetrain(mixed $value): ?string
    {
        $d = strtolower(trim((string) $value));

        return match (true) {
            $d === '' => null,
            str_contains($d, 'front') => 'FWD',
            str_contains($d, 'rear') => 'RWD',
            str_contains($d, 'all wheel') || str_contains($d, 'awd') => 'AWD',
            str_contains($d, '4x4') || str_contains($d, 'four wheel') || str_contains($d, '4wd') => '4WD',
            default => 'OTHER',
        };
    }

    private function bodyType(mixed $value): ?string
    {
        $b = strtolower(trim((string) $value));

        return match (true) {
            $b === '' => null,
            str_contains($b, 'hatch') => 'HATCHBACK',
            str_contains($b, 'sedan') || str_contains($b, 'saloon') => 'SEDAN',
            str_contains($b, 'coupe') => 'COUPE',
            str_contains($b, 'convertible') || str_contains($b, 'cabrio') || str_contains($b, 'roadster') => 'CONVERTIBLE',
            str_contains($b, 'wagon') || str_contains($b, 'estate') => 'WAGON',
            str_contains($b, 'crossover') => 'CROSSOVER',
            str_contains($b, 'suv') => 'SUV',
            str_contains($b, 'mpv') || str_contains($b, 'minivan') => 'MPV',
            str_contains($b, 'pick') => 'PICKUP',
            str_contains($b, 'minibus') => 'MINIBUS',
            str_contains($b, 'bus') => 'BUS',
            str_contains($b, 'van') => 'VAN',
            default => null,
        };
    }

    private function year(mixed $v): ?int
    {
        return is_numeric($v) && (int) $v >= 1900 && (int) $v <= (int) now()->year + 1 ? (int) $v : null;
    }

    private function int(mixed $v, int $max): ?int
    {
        return is_numeric($v) && (int) round((float) $v) >= 1 && (int) round((float) $v) <= $max ? (int) round((float) $v) : null;
    }

    /** @param class-string<VehicleGeneration|VehicleVariant> $class */
    private function uniqueCode(string $class, string $code): string
    {
        $base = $code;
        for ($i = 2; $class::where('code', $code)->exists(); $i++) {
            $code = $base.'_'.$i;
        }

        return $code;
    }
}
