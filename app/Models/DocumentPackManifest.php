<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** What one lifecycle event (issuance, endorsement 001, renewal, claim) required, and what it produced. */
final class DocumentPackManifest extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'policy_id', 'pack_code', 'trigger', 'event_reference', 'event_label', 'sequence', 'policy_version', 'items', 'generated_at'];

    protected function casts(): array
    {
        return ['items' => 'array', 'generated_at' => 'datetime'];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'pack_manifest_id');
    }
}
