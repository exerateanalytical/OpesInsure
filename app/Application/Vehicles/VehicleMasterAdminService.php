<?php

declare(strict_types=1);

namespace App\Application\Vehicles;

use App\Models\User;
use App\Models\Vehicles\RiskAssetVehicle;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleMakeAlias;
use App\Models\Vehicles\VehicleMasterChange;
use App\Models\Vehicles\VehicleModel;
use App\Models\Vehicles\VehicleModelAlias;
use App\Models\Vehicles\VehicleReferenceValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin write side of the vehicle master (Filament "Vehicle master data").
 * Every change stamps admin_modified_at (so the seeder stops refreshing that
 * row) and writes a vehicle_master_changes row. Nothing is ever deleted.
 */
final class VehicleMasterAdminService
{
    public const MAKE_EDITABLE = ['name', 'country_of_origin', 'market_priority', 'cameroon_status', 'segment', 'ui_rank_cameroon', 'ui_rank_chinese', 'active', 'provenance'];

    public const MODEL_EDITABLE = ['name', 'segment', 'status', 'active'];

    public function createMake(array $data, ?User $actor, string $provenance = 'MANUAL_VERIFIED'): VehicleMake
    {
        return DB::transaction(function () use ($data, $actor, $provenance) {
            $name = trim((string) ($data['name'] ?? ''));
            $code = VehicleText::code((string) ($data['code'] ?? $name));
            if ($name === '' || $code === '') {
                throw ValidationException::withMessages(['name' => 'A make name is required.']);
            }
            if (VehicleMake::where('code', $code)->exists()) {
                throw ValidationException::withMessages(['code' => "Make $code already exists."]);
            }

            $make = VehicleMake::create([
                'code' => $code,
                'name' => $name,
                'normalized_name' => VehicleText::normalize($name),
                'country_of_origin' => isset($data['country_of_origin']) ? strtoupper((string) $data['country_of_origin']) : null,
                'market_priority' => $data['market_priority'] ?? 'NORMAL',
                'cameroon_status' => $data['cameroon_status'] ?? 'UNVERIFIED',
                'segment' => $data['segment'] ?? 'PASSENGER',
                'provenance' => $data['provenance'] ?? $provenance,
                'ui_rank_cameroon' => $data['ui_rank_cameroon'] ?? null,
                'ui_rank_chinese' => $data['ui_rank_chinese'] ?? null,
                'active' => true,
                'admin_modified_at' => now(),
            ]);
            $this->log($make, 'CREATED', null, $make->only(['code', 'name', 'segment', 'provenance']), $actor);

            return $make;
        });
    }

    public function updateMake(VehicleMake $make, array $changes, ?User $actor): VehicleMake
    {
        $changes = array_intersect_key($changes, array_flip(self::MAKE_EDITABLE));
        if (isset($changes['name'])) {
            $changes['normalized_name'] = VehicleText::normalize($changes['name']);
        }

        return $this->applyChanges($make, $changes, $actor);
    }

    public function createModel(VehicleMake $make, array $data, ?User $actor, string $provenance = 'MANUAL_VERIFIED'): VehicleModel
    {
        return DB::transaction(function () use ($make, $data, $actor, $provenance) {
            $name = trim((string) ($data['name'] ?? ''));
            $normalized = VehicleText::normalize($name);
            if ($normalized === '') {
                throw ValidationException::withMessages(['name' => 'A model name is required.']);
            }
            if (VehicleModel::where('make_id', $make->id)->where('normalized_name', $normalized)->exists()) {
                throw ValidationException::withMessages(['name' => "{$make->name} $name already exists."]);
            }
            $code = $make->code.'_'.VehicleText::code($name);
            if (VehicleModel::where('code', $code)->exists()) {
                $code .= '_'.substr(md5($name.microtime()), 0, 4);
            }

            $model = VehicleModel::create([
                'code' => $code, 'make_id' => $make->id, 'name' => $name, 'normalized_name' => $normalized,
                'segment' => $data['segment'] ?? $make->segment, 'status' => $data['status'] ?? 'ACTIVE',
                'provenance' => $data['provenance'] ?? $provenance, 'active' => true, 'admin_modified_at' => now(),
            ]);
            $this->log($model, 'CREATED', null, $model->only(['code', 'name', 'segment', 'provenance']), $actor);

            return $model;
        });
    }

    public function updateModel(VehicleModel $model, array $changes, ?User $actor): VehicleModel
    {
        $changes = array_intersect_key($changes, array_flip(self::MODEL_EDITABLE));
        if (isset($changes['name'])) {
            $changes['normalized_name'] = VehicleText::normalize($changes['name']);
        }

        return $this->applyChanges($model, $changes, $actor);
    }

    public function markModelHistorical(VehicleModel $model, ?User $actor): VehicleModel
    {
        return $this->applyChanges($model, ['status' => 'HISTORICAL'], $actor);
    }

    public function deactivateModel(VehicleModel $model, ?User $actor): VehicleModel
    {
        return $this->applyChanges($model, ['active' => false], $actor);
    }

    public function updateReferenceValue(VehicleReferenceValue $value, array $changes, ?User $actor): VehicleReferenceValue
    {
        return $this->applyChanges($value, array_intersect_key($changes, array_flip(['label_en', 'label_fr', 'sort_order', 'active'])), $actor);
    }

    /** Returns false when the alias already exists (anywhere) or equals the make name. */
    public function addMakeAlias(VehicleMake $make, string $alias, ?User $actor): bool
    {
        $normalized = VehicleText::normalize($alias);
        if ($normalized === '' || $normalized === $make->normalized_name || VehicleMakeAlias::where('normalized_alias', $normalized)->exists()
            || VehicleMake::where('normalized_name', $normalized)->where('id', '!=', $make->id)->where('active', true)->exists()) {
            return false;
        }
        $row = VehicleMakeAlias::create(['make_id' => $make->id, 'alias' => trim($alias), 'normalized_alias' => $normalized, 'provenance' => 'MANUAL_VERIFIED']);
        $this->log($make, 'ALIAS_ADDED', null, ['alias' => $row->alias], $actor);

        return true;
    }

    public function addModelAlias(VehicleModel $model, string $alias, ?User $actor): bool
    {
        $normalized = VehicleText::normalize($alias);
        if ($normalized === '' || $normalized === $model->normalized_name
            || VehicleModelAlias::where('make_id', $model->make_id)->where('normalized_alias', $normalized)->exists()
            || VehicleModel::where('make_id', $model->make_id)->where('normalized_name', $normalized)->exists()) {
            return false;
        }
        $row = VehicleModelAlias::create(['model_id' => $model->id, 'make_id' => $model->make_id, 'alias' => trim($alias), 'normalized_alias' => $normalized, 'provenance' => 'MANUAL_VERIFIED']);
        $this->log($model, 'ALIAS_ADDED', null, ['alias' => $row->alias], $actor);

        return true;
    }

    /**
     * Folds a duplicate make into the canonical one: models, aliases and
     * vehicle records move over, the duplicate's name becomes an alias, and
     * the duplicate is deactivated with merged_into_id (kept, never deleted).
     */
    public function mergeMake(VehicleMake $duplicate, VehicleMake $target, ?User $actor): VehicleMake
    {
        if ($duplicate->id === $target->id) {
            throw ValidationException::withMessages(['target' => 'Cannot merge a make into itself.']);
        }

        return DB::transaction(function () use ($duplicate, $target, $actor) {
            foreach (VehicleModel::where('make_id', $duplicate->id)->get() as $model) {
                $clash = VehicleModel::where('make_id', $target->id)->where('normalized_name', $model->normalized_name)->first();
                if ($clash) {
                    // Same model exists under the target: keep the duplicate row (history) but retire it.
                    RiskAssetVehicle::where('model_id', $model->id)->update(['model_id' => $clash->id]);
                    $model->update(['make_id' => $target->id, 'normalized_name' => $model->normalized_name.'#'.substr($model->id, 0, 8), 'active' => false, 'admin_modified_at' => now()]);
                    $this->log($model, 'MERGED', ['make' => $duplicate->code], ['make' => $target->code, 'into_model' => $clash->code], $actor);

                    continue;
                }
                $model->update(['make_id' => $target->id, 'admin_modified_at' => now()]);
            }
            foreach (VehicleModelAlias::where('make_id', $duplicate->id)->get() as $alias) {
                if (VehicleModelAlias::where('make_id', $target->id)->where('normalized_alias', $alias->normalized_alias)->exists()) {
                    $alias->update(['make_id' => $target->id, 'normalized_alias' => $alias->normalized_alias.'#'.substr($alias->id, 0, 8)]);

                    continue;
                }
                $alias->update(['make_id' => $target->id]);
            }
            VehicleMakeAlias::where('make_id', $duplicate->id)->update(['make_id' => $target->id]);
            if (! VehicleMakeAlias::where('normalized_alias', $duplicate->normalized_name)->exists() && $duplicate->normalized_name !== $target->normalized_name) {
                VehicleMakeAlias::create(['make_id' => $target->id, 'alias' => $duplicate->name, 'normalized_alias' => $duplicate->normalized_name, 'provenance' => 'MANUAL_VERIFIED']);
            }
            RiskAssetVehicle::where('make_id', $duplicate->id)->update(['make_id' => $target->id]);

            $before = $duplicate->only(['active', 'merged_into_id']);
            $duplicate->update(['active' => false, 'merged_into_id' => $target->id, 'admin_modified_at' => now()]);
            $this->log($duplicate, 'MERGED', $before, ['merged_into' => $target->code], $actor);

            return $target->fresh();
        });
    }

    public function log(Model $entity, string $action, ?array $before, ?array $after, ?User $actor, ?string $reason = null): void
    {
        VehicleMasterChange::create([
            'entity_type' => match (true) {
                $entity instanceof VehicleMake => 'vehicle_make',
                $entity instanceof VehicleModel => 'vehicle_model',
                $entity instanceof VehicleReferenceValue => 'vehicle_reference_value',
                default => $entity->getTable(),
            },
            'entity_id' => $entity->getKey(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'actor_id' => $actor?->id,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @template T of Model
     *
     * @param  T  $entity
     * @return T
     */
    private function applyChanges(Model $entity, array $changes, ?User $actor): Model
    {
        return DB::transaction(function () use ($entity, $changes, $actor) {
            $entity->fill($changes);
            if (! $entity->isDirty()) {
                return $entity;
            }
            $before = array_intersect_key($entity->getOriginal(), $entity->getDirty());
            $entity->admin_modified_at = now();
            $entity->save();
            $this->log($entity, 'UPDATED', $before, array_diff_key($entity->getChanges(), ['updated_at' => 1, 'admin_modified_at' => 1]), $actor);

            return $entity;
        });
    }
}
