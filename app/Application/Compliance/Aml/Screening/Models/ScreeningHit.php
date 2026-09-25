<?php

declare(strict_types=1);

namespace App\Application\Compliance\Aml\Screening\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Agent E8 — REQ-AML-001 explainable match of a party against a list entry, with its disposition. */
final class ScreeningHit extends Model
{
    use HasUuids;

    public const DISPOSITIONS = ['FALSE_POSITIVE', 'TRUE_MATCH', 'ESCALATED'];

    protected $table = 'screening_hits';

    protected $guarded = [];

    protected $casts = ['explanation' => 'array', 'score' => 'float', 'proposed_at' => 'datetime', 'disposed_at' => 'datetime'];

    /** Blocks bind / issue / payout: undisposed, or disposed as TRUE_MATCH / ESCALATED. */
    public function isBlocking(): bool
    {
        return $this->status !== 'DISPOSED' || in_array($this->disposition, ['TRUE_MATCH', 'ESCALATED'], true);
    }
}
