<?php

declare(strict_types=1);

namespace App\Models\MasterData\Concerns;

use App\Application\MasterData\MasterDataCache;
use LogicException;

/**
 * Seeded master data is never deleted (mirrors the PostgreSQL BEFORE DELETE
 * trigger from 2026_09_29_100001): deactivate with status INACTIVE instead.
 * Every write bumps the owning domain's catalog_version so clients resync.
 */
trait ProtectsSeededMasterData
{
    public static function bootProtectsSeededMasterData(): void
    {
        static::deleting(function ($model): void {
            if ((bool) ($model->getOriginal('is_seeded') ?? false)) {
                throw new LogicException(class_basename($model).' '.$model->getKey().' is seeded master data and cannot be deleted; set it INACTIVE instead.');
            }
        });
        // The seeder writes through the query builder, so model updates are admin/API edits:
        // mark seeded values as admin-modified (the seeder then leaves them alone) and audit.
        static::updating(function ($model): void {
            if ($model->getTable() === 'master_data_values' && $model->is_seeded
                && array_intersect(array_keys($model->getDirty()), ['label_en', 'label_fr', 'description_en', 'description_fr', 'parent_code', 'attributes', 'status', 'sort_order', 'is_common'])) {
                $model->admin_modified_at = now();
            }
        });
        static::updated(function ($model): void {
            $changes = array_diff_key($model->getChanges(), array_flip(['updated_at', 'search_text', 'admin_modified_at']));
            if ($changes !== []) {
                \App\Models\MasterData\MasterDataChange::create([
                    'entity_type' => strtoupper(str_replace('MasterData', '', class_basename($model))), 'entity_id' => $model->getKey(),
                    'domain_code' => $model->domainCodeForCache(), 'action' => isset($changes['status']) ? ($changes['status'] === 'INACTIVE' ? 'DEACTIVATED' : 'REACTIVATED') : 'UPDATED',
                    'before' => array_intersect_key($model->getOriginal(), $changes), 'after' => $changes, 'actor_id' => auth()->id(), 'source' => 'ADMIN',
                ]);
            }
        });
        static::created(function ($model): void {
            \App\Models\MasterData\MasterDataChange::create([
                'entity_type' => strtoupper(str_replace('MasterData', '', class_basename($model))), 'entity_id' => $model->getKey(),
                'domain_code' => $model->domainCodeForCache(), 'action' => 'CREATED', 'after' => array_diff_key($model->getAttributes(), ['search_text' => 1]), 'actor_id' => auth()->id(), 'source' => 'ADMIN',
            ]);
        });
        static::saved(fn ($model) => MasterDataCache::touch($model->domainCodeForCache()));
        static::deleted(fn ($model) => MasterDataCache::touch($model->domainCodeForCache()));
    }

    public function domainCodeForCache(): ?string
    {
        return $this->getAttribute('domain_code');
    }
}
