<?php

declare(strict_types=1);

namespace App\Models\DocumentCatalogue;

use App\Models\DocumentCatalogue\Concerns\ProtectsSeededCatalogueData;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Protected institutional document catalogue data (seeded rows undeletable). */
final class DocumentType extends Model
{
    use HasUuids, ProtectsSeededCatalogueData;

    protected $table = 'document_types';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issuer_authority' => 'array', 'stages' => 'array', 'generation_triggers' => 'array', 'aliases' => 'array', 'verifiable' => 'boolean', 'is_evidence' => 'boolean', 'is_seeded' => 'boolean', 'effective_from' => 'date', 'effective_until' => 'date'];
    }

    public function label(string $locale = 'fr'): string
    {
        return str_starts_with($locale, 'en') ? $this->name_en : $this->name_fr;
    }

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_type_id', 'type_id');
    }

    public function subtypes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'parent_type_id', 'type_id');
    }
}
