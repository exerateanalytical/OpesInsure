<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\Vehicles\VehicleDataSource;
use App\Application\Vehicles\VehicleReferenceLabels;
use App\Application\Vehicles\VehicleText;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleMakeAlias;
use App\Models\Vehicles\VehicleManufacturer;
use App\Models\Vehicles\VehicleMasterChange;
use App\Models\Vehicles\VehicleMasterReview;
use App\Models\Vehicles\VehicleMasterSource;
use App\Models\Vehicles\VehicleModel;
use App\Models\Vehicles\VehicleModelAlias;
use App\Models\Vehicles\VehicleReferenceValue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reference data (not demo) from database/data/cameroon_vehicle_master_2026.json.
 * Runs in every environment. Idempotent and protected:
 *  - never deletes anything;
 *  - inserts what is missing;
 *  - refreshes canonical fields of a row only while no admin has edited it
 *    (admin_modified_at IS NULL), so admin changes survive every deploy.
 */
final class VehicleMasterDataSeeder extends Seeder
{
    public const DATA_FILE = 'data/cameroon_vehicle_master_2026.json';

    /** @var array<string, true> preloaded model codes and "make_id|normalized_name" keys */
    private array $modelKeys = [];

    /** @var array<string, true> preloaded normalized make aliases */
    private array $makeAliasKeys = [];

    /** @var array<string, int> */
    public array $counts = ['makes' => 0, 'models' => 0, 'make_aliases' => 0, 'model_aliases' => 0, 'reference_values' => 0, 'manufacturers' => 0];

    public function run(): void
    {
        $raw = file_get_contents(database_path(self::DATA_FILE));
        $data = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        $provenance = $data['provenance_default'] ?? 'MANUAL_VERIFIED';

        DB::transaction(function () use ($data, $raw, $provenance): void {
            $source = VehicleMasterSource::firstOrCreate(['code' => 'CM_VEHICLE_MASTER_2026'], ['name' => $data['source'], 'provenance' => $provenance]);
            $source->update(['content_hash' => hash('sha256', (string) $raw), 'imported_at' => now()]);

            $this->seedReferenceValues($data['enums']);
            foreach (VehicleModel::query()->get(['code', 'make_id', 'normalized_name']) as $m) {
                $this->modelKeys[$m->code] = true;
                $this->modelKeys[$m->make_id.'|'.$m->normalized_name] = true;
            }
            $this->makeAliasKeys = VehicleMakeAlias::query()->pluck('normalized_alias')->flip()->map(fn () => true)->all();

            $cmRank = array_flip($data['ui_priority_cameroon']);
            $cnRank = array_flip($data['ui_priority_chinese']);

            foreach ($data['makes'] as $row) {
                $manufacturer = $this->manufacturer($row);
                $make = VehicleMake::where('code', $row['code'])->first();
                $canonical = [
                    'name' => $row['name'],
                    'normalized_name' => VehicleText::normalize($row['name']),
                    'country_of_origin' => $row['country_of_origin'],
                    'manufacturer_id' => $manufacturer?->id,
                    'market_priority' => $row['market_priority'],
                    'cameroon_status' => $row['cameroon_status'],
                    'segment' => $row['segment'],
                    'ui_rank_cameroon' => isset($cmRank[$row['code']]) ? $cmRank[$row['code']] + 1 : null,
                    'ui_rank_chinese' => isset($cnRank[$row['code']]) ? $cnRank[$row['code']] + 1 : null,
                ];

                if (! $make) {
                    $make = VehicleMake::create(['code' => $row['code'], 'provenance' => $provenance, 'source_id' => $source->id, 'active' => true] + $canonical);
                    $this->log('vehicle_make', $make->id, 'SEEDED', $canonical);
                    $this->counts['makes']++;
                } elseif ($make->admin_modified_at === null && $make->merged_into_id === null) {
                    $make->fill($canonical);
                    if ($make->isDirty()) {
                        $before = array_intersect_key($make->getOriginal(), $make->getDirty());
                        $make->save();
                        $this->log('vehicle_make', $make->id, 'SEED_REFRESHED', $make->getChanges(), $before);
                    }
                }

                // "SsangYong / KGM", "Sinotruk / HOWO": each part of a compound canonical name also resolves.
                $parts = str_contains($row['name'], '/') ? array_map('trim', explode('/', $row['name'])) : [];
                foreach ([...($row['aliases'] ?? []), ...$parts] as $alias) {
                    $this->makeAlias($make, $alias, $provenance);
                }

                foreach ($row['models'] as $name) {
                    $this->model($make, $name, $row['segment'], $provenance);
                }
            }

            foreach ($data['model_aliases'] ?? [] as $entry) {
                $make = VehicleMake::where('code', $entry['make'])->first();
                $model = $make ? VehicleModel::where('make_id', $make->id)->where('normalized_name', VehicleText::normalize($entry['model']))->first() : null;
                foreach ($model ? $entry['aliases'] : [] as $alias) {
                    $normalized = VehicleText::normalize($alias);
                    if (! VehicleModelAlias::where('make_id', $make->id)->where('normalized_alias', $normalized)->exists()) {
                        VehicleModelAlias::create(['model_id' => $model->id, 'make_id' => $make->id, 'alias' => $alias, 'normalized_alias' => $normalized, 'provenance' => $provenance]);
                        $this->counts['model_aliases']++;
                    }
                }
            }

            $this->seedAfricaConfig($provenance);
            $this->seedCandidateMakes();
        });
    }

    public const AFRICA_CONFIG_FILE = 'data/vehicle_master_config_africa_2026.json';

    /**
     * Merges the owner-supplied Cameroon & Africa core list into the master:
     * makes and models are matched by code, name and alias first (never
     * duplicated); only genuinely missing ones are added, with data_source
     * OPESINSURE_VERIFIED_OVERRIDE. Existing rows are left untouched.
     */
    private function seedAfricaConfig(string $provenance): void
    {
        $path = database_path(self::AFRICA_CONFIG_FILE);
        if (! is_file($path)) {
            return;
        }
        $config = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $catalogue = app(\App\Application\Vehicles\VehicleCatalogueService::class);
        $rows = [];
        foreach ($config['makes'] ?? [] as $row) {
            $rows[] = ['code' => $row['code'] ?? null, 'name' => $row['name'], 'models' => $row['models'] ?? [], 'segment' => 'PASSENGER'];
        }
        foreach ($config['commercial_vehicle_makes'] ?? [] as $row) {
            $rows[] = is_array($row) ? ['code' => $row['code'] ?? null, 'name' => $row['name'], 'models' => $row['models'] ?? [], 'segment' => 'COMMERCIAL']
                : ['code' => null, 'name' => (string) $row, 'models' => [], 'segment' => 'COMMERCIAL'];
        }

        foreach ($rows as $row) {
            $make = ($row['code'] ? VehicleMake::where('code', VehicleText::code($row['code']))->first() : null)
                ?? $catalogue->resolveMake($row['name']);
            if (! $make) {
                $code = VehicleText::code($row['code'] ?? $row['name']);
                $make = VehicleMake::create(['code' => $code, 'name' => $row['name'], 'normalized_name' => VehicleText::normalize($row['name']),
                    'segment' => $row['segment'], 'cameroon_status' => 'UNVERIFIED', 'market_priority' => 'NORMAL',
                    'provenance' => $provenance, 'data_source' => VehicleDataSource::OVERRIDE, 'active' => true]);
                $this->log('vehicle_make', $make->id, 'SEEDED', ['code' => $code, 'name' => $row['name'], 'source' => self::AFRICA_CONFIG_FILE]);
                $this->counts['makes']++;
            }
            if ($make->merged_into_id) {
                $make = VehicleMake::find($make->merged_into_id) ?? $make;
            }
            foreach ($row['models'] as $name) {
                if ($catalogue->resolveModel($make, $name)) {
                    continue;
                }
                $code = $make->code.'_'.VehicleText::code($name);
                if (VehicleModel::where('code', $code)->exists()) {
                    continue;
                }
                $model = VehicleModel::create(['code' => $code, 'make_id' => $make->id, 'name' => $name, 'normalized_name' => VehicleText::normalize($name),
                    'segment' => $make->segment, 'status' => 'ACTIVE', 'provenance' => $provenance, 'data_source' => VehicleDataSource::OVERRIDE, 'active' => true]);
                $this->log('vehicle_model', $model->id, 'SEEDED', ['code' => $code, 'make' => $make->code, 'name' => $name, 'source' => self::AFRICA_CONFIG_FILE]);
                $this->counts['models']++;
            }
        }
    }

    /**
     * Makes proposed for the catalogue but not in the verified master file.
     * They are NOT added: each becomes a make-only review entry so an admin
     * checks Cameroon market presence before approving, merging or rejecting.
     */
    public const CANDIDATE_MAKES = ['Datsun', 'McLaren', 'Mahindra', 'Lada', 'UAZ'];

    private function seedCandidateMakes(): void
    {
        foreach (self::CANDIDATE_MAKES as $name) {
            $normalized = VehicleText::normalize($name);
            $known = VehicleMake::where('normalized_name', $normalized)->exists() || VehicleMakeAlias::where('normalized_alias', $normalized)->exists();
            if ($known || VehicleMasterReview::where('make_text', $name)->where('model_text', '')->whereNull('submitted_by')->exists()) {
                continue;
            }
            VehicleMasterReview::create([
                'status' => VehicleMasterReview::STATUS_PENDING,
                'make_text' => $name,
                'model_text' => '',
                'payload' => ['source' => 'CATALOGUE_CANDIDATE', 'note' => 'Candidate make not in the verified 2026 master file; verify Cameroon market presence before approving.'],
            ]);
        }
    }

    private function seedReferenceValues(array $enums): void
    {
        foreach (VehicleReferenceLabels::GROUPS as $key => $group) {
            $entries = array_values($enums[$key] ?? []);
            foreach (VehicleReferenceLabels::EXTRA_VALUES[$group] ?? [] as $code => [$en, $fr]) {
                $entries[] = [$code, $en, $fr];
            }
            foreach ($entries as $i => $entry) {
                [$code, $en, $fr] = is_array($entry)
                    ? [$entry[0], $entry[1], $entry[2]]
                    : [$entry, ...VehicleReferenceLabels::for($group, $entry)];

                $existing = VehicleReferenceValue::where(['group' => $group, 'code' => $code])->first();
                if (! $existing) {
                    VehicleReferenceValue::create(['group' => $group, 'code' => $code, 'label_en' => $en, 'label_fr' => $fr, 'sort_order' => $i + 1, 'active' => true]);
                    $this->counts['reference_values']++;
                } elseif ($existing->admin_modified_at === null) {
                    $existing->update(['label_en' => $en, 'label_fr' => $fr, 'sort_order' => $i + 1]);
                }
            }
        }
    }

    private function manufacturer(array $row): ?VehicleManufacturer
    {
        $code = $row['manufacturer_group'] ?? null;
        if (! $code) {
            return null;
        }

        $m = VehicleManufacturer::firstOrCreate(['code' => $code], [
            'name' => Str::of($code)->replace('_', ' ')->title()->toString(),
            'country_of_origin' => $row['country_of_origin'],
        ]);
        if ($m->wasRecentlyCreated) {
            $this->counts['manufacturers']++;
        }

        return $m;
    }

    private function makeAlias(VehicleMake $make, string $alias, string $provenance): void
    {
        $normalized = VehicleText::normalize($alias);
        if ($normalized === '' || $normalized === $make->normalized_name || isset($this->makeAliasKeys[$normalized])) {
            return;
        }
        $this->makeAliasKeys[$normalized] = true;
        VehicleMakeAlias::create(['make_id' => $make->id, 'alias' => $alias, 'normalized_alias' => $normalized, 'provenance' => $provenance]);
        $this->counts['make_aliases']++;
    }

    private function model(VehicleMake $make, string $name, string $segment, string $provenance): void
    {
        $code = $make->code.'_'.VehicleText::code($name);
        // An admin may have merged the make: the model then lives under the
        // canonical make but keeps its original code — match on code first.
        $normalized = VehicleText::normalize($name);
        if (isset($this->modelKeys[$code]) || isset($this->modelKeys[$make->id.'|'.$normalized])) {
            return;
        }
        $this->modelKeys[$code] = $this->modelKeys[$make->id.'|'.$normalized] = true;

        $model = VehicleModel::create(['code' => $code, 'make_id' => $make->id, 'name' => $name, 'normalized_name' => $normalized, 'segment' => $segment, 'status' => 'ACTIVE', 'provenance' => $provenance, 'active' => true]);
        $this->log('vehicle_model', $model->id, 'SEEDED', ['code' => $code, 'make' => $make->code, 'name' => $name]);
        $this->counts['models']++;
    }

    private function log(string $type, string $id, string $action, array $after, ?array $before = null): void
    {
        VehicleMasterChange::create(['entity_type' => $type, 'entity_id' => $id, 'action' => $action, 'before' => $before, 'after' => $after, 'reason' => 'opesinsure:seed-vehicles', 'occurred_at' => now()]);
    }
}
