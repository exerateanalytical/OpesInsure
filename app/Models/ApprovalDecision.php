<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Append-only checker decision (DB trigger rejects UPDATE/DELETE and maker = decider). */
final class ApprovalDecision extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];
}
