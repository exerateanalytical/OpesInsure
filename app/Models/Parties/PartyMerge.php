<?php

declare(strict_types=1);

namespace App\Models\Parties;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Batch 4A golden record (REQ-PTY-002/003/004). Written only by App\Application\Customers services. */
final class PartyMerge extends Model
{
    use HasUuids;

    protected $table = 'party_merges';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['survivorship_rules' => 'array', 'survivorship_log' => 'array', 'before_snapshot' => 'array', 'moved_rows' => 'array', 'decided_at' => 'datetime', 'unmerged_at' => 'datetime'];
    }
}
