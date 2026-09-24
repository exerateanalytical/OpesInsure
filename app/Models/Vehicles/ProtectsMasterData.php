<?php

declare(strict_types=1);

namespace App\Models\Vehicles;

use LogicException;

/**
 * Vehicle master data is never deleted: quotes, policies and claims keep
 * pointing at it. Deactivate, mark historical, or merge instead.
 */
trait ProtectsMasterData
{
    protected static function bootProtectsMasterData(): void
    {
        static::deleting(function (): void {
            throw new LogicException('Vehicle master data cannot be deleted; deactivate or merge it instead.');
        });
    }
}
