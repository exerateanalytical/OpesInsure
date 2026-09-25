<?php

declare(strict_types=1);

namespace App\Models\Parties;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Batch 4A golden record (REQ-PTY-002/003/004). Written only by App\Application\Customers services. */
final class OwnershipInterest extends Model
{
    use HasUuids;

    protected $table = 'ownership_interests';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['percentage' => 'decimal:4', 'valid_from' => 'date', 'valid_to' => 'date'];
    }
}
