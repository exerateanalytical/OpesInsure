<?php

declare(strict_types=1);

namespace App\Models\Regulatory;

use App\Models\Regulatory\Concerns\ProtectsSeededRegulatoryData;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Canonical CIMA branch (`insurance_branches`, ADR-003 amendment 2026-09-25); `regulatory_branches` is a compatibility view. */
final class RegulatoryBranch extends Model
{
    use HasUuids, ProtectsSeededRegulatoryData;

    protected $table = 'insurance_branches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'is_seeded' => 'boolean', 'number' => 'integer', 'reserved' => 'boolean', 'accessory_allowed' => 'boolean', 'complementary_covers_allowed' => 'boolean', 'is_compulsory' => 'boolean'];
    }

    /** Currently effective, active branches. */
    public function scopeCurrent($q, ?string $on = null)
    {
        $on ??= now()->toDateString();

        return $q->where('status', 'ACTIVE')->whereDate('effective_from', '<=', $on)->where(fn ($w) => $w->whereNull('effective_until')->orWhereDate('effective_until', '>=', $on));
    }
}
