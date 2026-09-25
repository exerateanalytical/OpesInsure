<?php

declare(strict_types=1);

namespace App\Application\Rules\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** REQ-RUL-001 / REQ-DUP-020 — the canonical risk-question set per product version (or line default). */
final class QuestionSet extends Model
{
    use HasUuids;

    protected $table = 'question_sets';

    protected $guarded = [];

    protected $casts = ['version' => 'integer', 'schema_version' => 'integer', 'presentation' => 'array', 'effective_from' => 'date', 'effective_until' => 'date', 'approved_at' => 'datetime'];

    public function questions(): HasMany
    {
        return $this->hasMany(ProductQuestion::class)->orderBy('display_order');
    }
}
