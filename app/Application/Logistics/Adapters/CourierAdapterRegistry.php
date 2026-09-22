<?php

declare(strict_types=1);

namespace App\Application\Logistics\Adapters;

use InvalidArgumentException;

final class CourierAdapterRegistry
{
    public function for(string $courierType): CourierConnectorAdapter
    {
        return match ($courierType) {
            'INTERNAL' => new InternalCourierAdapter,
            default => throw new InvalidArgumentException('Unsupported courier type.'),
        };
    }
}
