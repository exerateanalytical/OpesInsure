<?php

declare(strict_types=1);

namespace App\Models\Parties;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Batch 4A golden record (REQ-PTY-002/003/004). Written only by App\Application\Customers services. */
final class PartyRole extends Model
{
    use HasUuids;

    protected $table = 'party_roles';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'valid_from' => 'datetime', 'valid_to' => 'datetime', 'recorded_at' => 'datetime', 'superseded_at' => 'datetime'];
    }
}
