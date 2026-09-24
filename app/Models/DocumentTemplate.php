<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A versioned document template (PLATFORM | INSURER | BROKER | REGULATORY); DRAFT -> REVIEW -> APPROVED -> PUBLISHED -> RETIRED. */
final class DocumentTemplate extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'document_type_code', 'ownership', 'carrier_id', 'broker_tenant_id', 'product_id', 'insurance_class', 'language', 'version', 'status', 'title_en', 'title_fr', 'content', 'content_hash', 'effective_from', 'effective_until', 'created_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'published_by', 'published_at', 'retired_at', 'retire_reason'];

    protected function casts(): array
    {
        return ['content' => 'array', 'effective_from' => 'date', 'effective_until' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'published_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function brokerTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'broker_tenant_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InsuranceProduct::class, 'product_id');
    }
}
