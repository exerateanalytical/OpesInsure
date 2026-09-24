<?php

declare(strict_types=1);

namespace App\Models\DocumentCatalogue;

use App\Models\DocumentCatalogue\Concerns\ProtectsSeededCatalogueData;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Protected institutional document catalogue data (seeded rows undeletable). */
final class DocumentProductType extends Model
{
    use HasUuids, ProtectsSeededCatalogueData;

    protected $table = 'document_product_types';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_seeded' => 'boolean', 'effective_from' => 'date', 'effective_until' => 'date'];
    }

    public function entries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DocumentRequirementMatrixEntry::class, 'product_type_code', 'code')->orderBy('sort_order');
    }
}
