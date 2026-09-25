<?php

declare(strict_types=1);

namespace App\Application\Import\Targets;

use App\Application\Vehicles\VehicleText;
use App\Models\Vehicles\VehicleGeneration;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Finds the make → model (→ generation) a vehicle import row points at, by code, name or alias. Never creates them. */
trait ResolvesVehicleModel
{
    private function resolveModel(array $row): VehicleModel
    {
        $make = trim((string) ($row['make'] ?? ''));
        $model = trim((string) ($row['model'] ?? ''));
        if ($make === '' || $model === '') {
            throw ValidationException::withMessages(['model' => 'make and model are required']);
        }
        $n = VehicleText::normalize($make);
        $m = VehicleMake::where('code', VehicleText::code($make))->orWhere('normalized_name', $n)->first()
            ?? VehicleMake::whereIn('id', DB::table('vehicle_make_aliases')->where('normalized_alias', $n)->select('make_id'))->first()
            ?? throw ValidationException::withMessages(['make' => "Unknown make \"$make\" (add it to the vehicle master first)"]);
        $mn = VehicleText::normalize($model);
        $found = VehicleModel::where('make_id', $m->id)->where(fn ($q) => $q->where('code', VehicleText::code($model))->orWhere('normalized_name', $mn))->first()
            ?? VehicleModel::whereIn('id', DB::table('vehicle_model_aliases')->where(['make_id' => $m->id, 'normalized_alias' => $mn])->select('model_id'))->first();

        return $found ?? throw ValidationException::withMessages(['model' => "Unknown model \"$model\" for {$m->name} (add it to the vehicle master first)"]);
    }

    private function resolveGeneration(VehicleModel $model, ?string $generation): ?VehicleGeneration
    {
        if ($generation === null || trim($generation) === '') {
            return null;
        }
        $n = VehicleText::normalize($generation);

        return VehicleGeneration::where('model_id', $model->id)->get()->first(fn ($g) => $g->code === strtoupper($generation) || VehicleText::normalize($g->name) === $n)
            ?? throw ValidationException::withMessages(['generation' => "Unknown generation \"$generation\" for {$model->name}"]);
    }

    /** Runs a domain create inside a rolled-back savepoint so validation is exactly the domain service's own. */
    private function dryRun(callable $create): ?string
    {
        DB::beginTransaction();
        try {
            $create();

            return null;
        } catch (ValidationException $e) {
            return (string) collect($e->errors())->flatten()->first();
        } finally {
            DB::rollBack();
        }
    }
}
