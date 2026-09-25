<?php

declare(strict_types=1);

namespace App\Application\Import\Targets;

use App\Application\Import\ImportTarget;
use App\Application\Vehicles\VehicleMasterAdminService;
use App\Application\Vehicles\VehicleText;
use App\Models\User;
use App\Models\Vehicles\VehicleGeneration;
use Illuminate\Validation\ValidationException;

/** Vehicle generations (REQ-VEH-001). Columns: make, model, name, year_from, year_to. Created through VehicleMasterAdminService. */
final class VehicleGenerationTarget implements ImportTarget
{
    use ResolvesVehicleModel;

    public function __construct(private readonly VehicleMasterAdminService $admin) {}

    public function key(): string
    {
        return 'vehicle_generations';
    }

    public function label(): string
    {
        return 'Vehicle generations';
    }

    public function fields(): array
    {
        return ['make' => true, 'model' => true, 'name' => true, 'year_from' => false, 'year_to' => false];
    }

    public function params(array $params): array
    {
        return [];
    }

    public function check(array $row, array $params, array &$seen): array
    {
        try {
            $model = $this->resolveModel($row);
        } catch (ValidationException $e) {
            return ['status' => 'ERROR', 'error' => (string) collect($e->errors())->flatten()->first()];
        }
        $name = (string) ($row['name'] ?? '');
        $key = $model->code.'/'.VehicleText::normalize($name);
        if (isset($seen[$key])) {
            return ['status' => 'ERROR', 'error' => "{$model->name} $name repeated in the file"];
        }
        $seen[$key] = true;
        $dupe = VehicleGeneration::where('model_id', $model->id)->get(['code', 'name'])->first(fn ($g) => VehicleText::normalize($g->name) === VehicleText::normalize($name));
        if ($dupe) {
            return ['status' => 'DUPLICATE', 'key' => $key, 'matches' => $dupe->code];
        }
        $error = $this->dryRun(fn () => $this->admin->createGeneration($model, $row, null));

        return $error ? ['status' => 'ERROR', 'error' => $error] : ['status' => 'NEW', 'key' => $key];
    }

    public function import(array $row, array $params, ?User $actor, string $batchId): string
    {
        return $this->admin->createGeneration($this->resolveModel($row), $row, $actor)->id;
    }

    public function finish(array $params): void {}
}
