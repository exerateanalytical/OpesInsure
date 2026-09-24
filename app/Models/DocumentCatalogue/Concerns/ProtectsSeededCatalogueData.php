<?php

declare(strict_types=1);

namespace App\Models\DocumentCatalogue\Concerns;

use LogicException;

/**
 * Seeded document catalogue rows are institutional data: never deleted and
 * never edited in place from the application. They may only be deactivated
 * (status / effective_until); content changes ship as a new catalogue_version
 * through opesinsure:seed-document-catalogue. Mirrors the PostgreSQL BEFORE
 * DELETE trigger from 2026_09_29_100001_create_document_catalogue.
 */
trait ProtectsSeededCatalogueData
{
    /** Columns that may change on a seeded row (deactivation). */
    protected static array $seededMutable = ['status', 'effective_until', 'updated_at'];

    public static function bootProtectsSeededCatalogueData(): void
    {
        static::deleting(function ($model): void {
            if ($model->isSeededCatalogue()) {
                throw new LogicException(class_basename($model).' '.$model->getKey().' is seeded document catalogue data and cannot be deleted; deactivate it instead.');
            }
        });
        static::updating(function ($model): void {
            if (! $model->isSeededCatalogue()) {
                return;
            }
            $changed = array_diff(array_keys($model->getDirty()), static::$seededMutable);
            if ($changed !== []) {
                throw new LogicException(class_basename($model).' '.$model->getKey().' is seeded document catalogue data; ship a new catalogue version instead of editing '.implode(', ', $changed).'.');
            }
        });
    }

    public function isSeededCatalogue(): bool
    {
        return (bool) ($this->getOriginal('is_seeded') ?? false);
    }

    public function scopeActive($query)
    {
        $t = $this->getTable();

        return $query->where("$t.status", 'ACTIVE')
            ->where(fn ($q) => $q->whereNull("$t.effective_until")->orWhereDate("$t.effective_until", '>=', now()->toDateString()));
    }

    public function deactivate(?string $until = null): static
    {
        $this->forceFill(['status' => 'INACTIVE', 'effective_until' => $until ?? now()->toDateString()])->save();

        return $this;
    }
}