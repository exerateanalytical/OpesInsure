<?php

declare(strict_types=1);

namespace App\Models\DocumentCatalogue;

use App\Models\DocumentCatalogue\Concerns\ProtectsSeededCatalogueData;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Protected institutional document catalogue data (seeded rows undeletable). */
final class DocumentPack extends Model
{
    use HasUuids, ProtectsSeededCatalogueData;

    protected $table = 'document_packs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['class_codes' => 'array', 'is_universal' => 'boolean', 'is_seeded' => 'boolean', 'effective_from' => 'date', 'effective_until' => 'date'];
    }

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DocumentPackItem::class, 'document_pack_id')->orderBy('sort_order');
    }
}
