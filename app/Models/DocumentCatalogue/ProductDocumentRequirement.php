<?php

declare(strict_types=1);

namespace App\Models\DocumentCatalogue;

use App\Models\InsuranceProduct;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Insurer / product-version adaptation of platform packs and the requirement
 * matrix (maker-checker). Never deleted — retired or rejected. Mirrors the
 * product_document_requirements_no_delete PostgreSQL trigger.
 */
final class ProductDocumentRequirement extends Model
{
    use HasUuids;

    /** Pack contents live in document_packs / document_pack_items of the same catalogue (REQ-DUP-004); the engine reads them via CatalogueSource. */
    public const KINDS = ['PRODUCT_TYPE', 'MATRIX_OVERRIDE'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (self $m) => throw new LogicException('Product document requirement '.$m->id.' cannot be deleted; retire it instead.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InsuranceProduct::class, 'insurance_product_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id', 'type_id');
    }
}