<?php

declare(strict_types=1);

namespace App\Application\Cases\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CaseTask extends Model
{
    use HasUuids;

    public const OPEN_STATES = ['OPEN', 'IN_PROGRESS', 'BLOCKED'];

    protected $table = 'case_tasks';

    protected $guarded = [];

    protected $casts = ['due_at' => 'datetime', 'completed_at' => 'datetime', 'overdue_notified_at' => 'datetime', 'result' => 'array'];

    public function case(): BelongsTo
    {
        return $this->belongsTo(WorkCase::class, 'case_id');
    }
}
