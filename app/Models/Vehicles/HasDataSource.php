<?php

declare(strict_types=1);

namespace App\Models\Vehicles;

use App\Application\Vehicles\VehicleDataSource;

/** Fills data_source from provenance when a writer did not set it explicitly. */
trait HasDataSource
{
    protected static function bootHasDataSource(): void
    {
        static::saving(function ($row): void {
            if (empty($row->data_source)) {
                $row->data_source = VehicleDataSource::fromProvenance($row->provenance);
            }
        });
    }
}
