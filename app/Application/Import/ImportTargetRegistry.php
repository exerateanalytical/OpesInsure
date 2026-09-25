<?php

declare(strict_types=1);

namespace App\Application\Import;

use Illuminate\Validation\ValidationException;

/** REQ-IMP-001 — the targets the generic pipeline can load. Other modules add theirs with register(). */
final class ImportTargetRegistry
{
    /** @var array<string, class-string<ImportTarget>> */
    private array $targets = [
        'master_data_values' => Targets\MasterDataValueTarget::class,
        'vehicle_generations' => Targets\VehicleGenerationTarget::class,
        'vehicle_variants' => Targets\VehicleVariantTarget::class,
    ];

    /** @param class-string<ImportTarget> $class */
    public function register(string $key, string $class): void
    {
        $this->targets[$key] = $class;
    }

    public function get(string $key): ImportTarget
    {
        if (! isset($this->targets[$key])) {
            throw ValidationException::withMessages(['target' => "Unknown import target $key."]);
        }

        return app($this->targets[$key]);
    }

    /** @return array<string, string> key => label */
    public function options(): array
    {
        return collect($this->targets)->map(fn ($c, $k) => $this->get($k)->label())->all();
    }
}
