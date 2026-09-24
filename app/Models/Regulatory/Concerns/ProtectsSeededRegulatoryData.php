<?php

declare(strict_types=1);

namespace App\Models\Regulatory\Concerns;

use LogicException;

/**
 * Seeded CIMA regulatory rows are never deleted and never rewritten in place:
 * a change is a new effective-dated row (new regulatory_version) and the old
 * row is closed with effective_until / status. Mirrors the PostgreSQL
 * BEFORE DELETE trigger from 2026_09_27_100001.
 */
trait ProtectsSeededRegulatoryData
{
    /** Columns that may change on a seeded row (closing a version). */
    protected static array $seededMutable = ['effective_until', 'status', 'updated_at'];

    public static function bootProtectsSeededRegulatoryData(): void
    {
        static::deleting(function ($model): void {
            if ($model->isSeededRegulatory()) {
                throw new LogicException(class_basename($model).' '.$model->getKey().' is seeded CIMA regulatory data and cannot be deleted.');
            }
        });
        static::updating(function ($model): void {
            if (! $model->isSeededRegulatory()) {
                return;
            }
            $changed = array_diff(array_keys($model->getDirty()), static::$seededMutable);
            if ($changed !== []) {
                throw new LogicException(class_basename($model).' '.$model->getKey().' is seeded CIMA regulatory data; create a new effective-dated version instead of editing '.implode(', ', $changed).'.');
            }
        });
    }

    public function isSeededRegulatory(): bool
    {
        return (bool) ($this->getOriginal('is_seeded') ?? false);
    }
}
