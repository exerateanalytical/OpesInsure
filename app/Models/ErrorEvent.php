<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** S12: one fingerprinted unhandled exception (ErrorEventRecorder). Platform-wide, no tenant column. */
final class ErrorEvent extends Model
{
    use HasUuids;

    public const OPEN = 'OPEN';

    public const RESOLVED = 'RESOLVED';

    public const IGNORED = 'IGNORED';

    protected $table = 'error_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime', 'occurrences' => 'integer', 'line' => 'integer'];
    }
}
