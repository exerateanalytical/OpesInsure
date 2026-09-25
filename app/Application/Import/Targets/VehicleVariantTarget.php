<?php

declare(strict_types=1);

namespace App\Application\Import\Targets;

use App\Application\Import\ImportTarget;
use App\Application\Vehicles\VehicleMasterAdminService;
use App\Application\Vehicles\VehicleText;
use App\Models\User;
use App\Models\Vehicles\VehicleVariant;
use Illuminate\Validation\ValidationException;

/**
 * Vehicle variants (REQ-VEH-001). Columns: make, model, generation (optional), name, year_from, year_to, body_type,
 * powertrain, hybrid_subtype, transmission, drive_type (vehicle_reference_values codes), engine_capacity_cc, power_hp,
 * power_kw, torque_nm, cylinders. Created through VehicleMasterAdminService (same validation as the admin screen).
 */
final class VehicleVariantTarget implements ImportTarget
{
    use ResolvesVehicleModel;

    private const ATTRIBUTES = ['name', 'year_from', 'year_to', 'body_type', 'powertrain', 'hybrid_subtype', 'transmission', 'drive_type',
        'engine_capacity_cc', 'power_hp', 'power_kw', 'torque_nm', 'cylinders'];

    private const CODES = ['body_type', 'powertrain', 'hybrid_subtype', 'transmission', 'drive_type'];

    public function __construct(private readonly VehicleMasterAdminService $admin) {}

    public function key(): string
    {
        return 'vehicle_variants';
    }

    public function label(): string
    {
        return 'Vehicle variants';
    }

    public function fields(): array
    {
        $fields = ['make' => true, 'model' => true, 'generation' => false];
        foreach (self::ATTRIBUTES as $a) {
            $fields[$a] = $a === 'name';
        }

        return $fields;
    }

    public function params(array $params): array
    {
        return [];
    }

    public function check(array $row, array $params, array &$seen): array
    {
        try {
            $model = $this->resolveModel($row);
            $generation = $this->resolveGeneration($model, $row['generation'] ?? null);
        } catch (ValidationException $e) {
            return ['status' => 'ERROR', 'error' => (string) collect($e->errors())->flatten()->first()];
        }
        $name = (string) ($row['name'] ?? '');
        $key = ($generation?->code ?? $model->code).'/'.VehicleText::normalize($name);
        if (isset($seen[$key])) {
            return ['status' => 'ERROR', 'error' => "Variant $name repeated in the file"];
        }
        $seen[$key] = true;
        $dupe = VehicleVariant::where('model_id', $model->id)->where('generation_id', $generation?->id)->get(['code', 'name'])
            ->first(fn ($v) => VehicleText::normalize($v->name) === VehicleText::normalize($name));
        if ($dupe) {
            return ['status' => 'DUPLICATE', 'key' => $key, 'matches' => $dupe->code];
        }
        $error = $this->dryRun(fn () => $this->admin->createVariant($model, $generation, $this->attributes($row), null));

        return $error ? ['status' => 'ERROR', 'error' => $error] : ['status' => 'NEW', 'key' => $key];
    }

    public function import(array $row, array $params, ?User $actor, string $batchId): string
    {
        $model = $this->resolveModel($row);

        return $this->admin->createVariant($model, $this->resolveGeneration($model, $row['generation'] ?? null), $this->attributes($row), $actor)->id;
    }

    public function finish(array $params): void {}

    private function attributes(array $row): array
    {
        $attrs = array_filter(array_intersect_key($row, array_flip(self::ATTRIBUTES)), fn ($v) => $v !== null);
        foreach (self::CODES as $f) {
            if (isset($attrs[$f])) {
                $attrs[$f] = strtoupper($attrs[$f]);
            }
        }

        return $attrs;
    }
}
