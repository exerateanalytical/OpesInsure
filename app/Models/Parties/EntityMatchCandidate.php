<?php

declare(strict_types=1);

namespace App\Models\Parties;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Batch 4A golden record (REQ-PTY-002/003/004). Written only by App\Application\Customers services. */
final class EntityMatchCandidate extends Model
{
    use HasUuids;

    protected $table = 'entity_match_candidates';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['reasons' => 'array', 'score' => 'float', 'reviewed_at' => 'datetime'];
    }
}
