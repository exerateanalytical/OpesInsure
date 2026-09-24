<?php

declare(strict_types=1);

namespace App\Models\DocumentCatalogue;

use App\Models\DocumentCatalogue\Concerns\ProtectsSeededCatalogueData;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Protected institutional document catalogue data (seeded rows undeletable). */
final class DocumentRequirementMatrixEntry extends Model
{
    use HasUuids, ProtectsSeededCatalogueData;

    protected $table = 'document_requirement_matrix';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qualifiers' => 'array', 'insurer_overridable' => 'boolean', 'is_seeded' => 'boolean', 'effective_from' => 'date', 'effective_until' => 'date'];
    }

    public const BASELINE = '__BASELINE__';

    public function documentType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id', 'type_id');
    }
}
