<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use LogicException;

/**
 * Rows seeded from the official DGTCFM/MINFI register must never be
 * deleted (owner instruction). Mirrors the PostgreSQL BEFORE DELETE
 * trigger added in 2026_09_26_090001.
 */
trait ProtectsOfficialRegister
{
    public static function bootProtectsOfficialRegister(): void
    {
        static::deleting(function ($model): void {
            if ($model->isOfficialRegister()) {
                throw new LogicException(class_basename($model).' '.$model->getKey().' is part of the official insurance register and cannot be deleted.');
            }
        });
    }

    public function isOfficialRegister(): bool
    {
        return (bool) ($this->getOriginal('is_official_register') ?? $this->getAttribute('is_official_register'));
    }
}
