<?php

declare(strict_types=1);

namespace App\Models\DocumentCatalogue;

use App\Models\DocumentCatalogue\Concerns\ProtectsSeededCatalogueData;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Protected institutional document catalogue data (seeded rows undeletable). */
final class DocumentPackItem extends Model
{
    use HasUuids, ProtectsSeededCatalogueData;

    protected $table = 'document_pack_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_seeded' => 'boolean', 'effective_from' => 'date', 'effective_until' => 'date'];
    }

    public function pack(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(DocumentPack::class, 'document_pack_id', 'id');
    }

    public function documentType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id', 'type_id');
    }
}
