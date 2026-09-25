<?php

declare(strict_types=1);

namespace App\Application\Capabilities\Models;

use App\Models\Carrier;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** REQ-AOM-001 — versioned insurer capability profile (never overwritten once submitted). */
final class CapabilityProfile extends Model
{
    use HasUuids;

    protected $table = 'carrier_capability_profiles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'datetime', 'effective_until' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'version' => 'integer', 'maturity_level' => 'integer'];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function modes(): HasMany
    {
        return $this->hasMany(CapabilityMode::class, 'profile_id');
    }
}
