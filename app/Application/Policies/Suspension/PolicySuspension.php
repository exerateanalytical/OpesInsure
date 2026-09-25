<?php

declare(strict_types=1);

namespace App\Application\Policies\Suspension;

use App\Models\Policy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One suspension episode of a policy (REQ-POL-006). */
final class PolicySuspension extends Model
{
    use HasUuids;

    public const OPEN_STATES = ['SUSPENDED', 'REINSTATEMENT_REQUESTED'];

    protected $table = 'policy_suspensions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['suspended_at' => 'datetime', 'reinstatement_requested_at' => 'datetime', 'reinstated_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }
}
