<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Maker-checker request to REVOKE / REPLACE / CANCEL an issued document. */
final class DocumentStatusChange extends Model
{
    use HasUuids;

    protected $fillable = ['document_id', 'action', 'reason', 'replacement_document_id', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'replacement_document_id');
    }
}
