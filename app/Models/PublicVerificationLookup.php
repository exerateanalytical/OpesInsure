<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One public verification lookup (QR page / API). Hashes only: never the raw reference, token or IP. Append-only. */
final class PublicVerificationLookup extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'token_presented' => 'boolean'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
