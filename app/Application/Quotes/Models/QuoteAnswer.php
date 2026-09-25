<?php

declare(strict_types=1);

namespace App\Application\Quotes\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REQ-QUO-003 / REQ-DUP-020: answers captured against the resolved QUOTE question set (version + schema hash). */
final class QuoteAnswer extends Model
{
    use HasUuids;

    protected $fillable = ['quote_id', 'question_set_id', 'question_set_version', 'schema_hash', 'answers', 'answers_hash', 'unanswered_required', 'answered_by', 'answered_at'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'unanswered_required' => 'array', 'answered_at' => 'datetime'];
    }
}
