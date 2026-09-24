<?php

declare(strict_types=1);

namespace App\Models\Regulatory;

use App\Models\Regulatory\Concerns\ProtectsSeededRegulatoryData;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use App\Models\Carrier;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
final class InsurerRegulatoryAuthorization extends Model
{
    use HasUuids, ProtectsSeededRegulatoryData;

    protected $table = 'insurer_regulatory_authorizations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'is_seeded' => 'boolean', 'is_demo' => 'boolean', 'approved_at' => 'datetime'];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(InsurerAuthorizedBranch::class, 'authorization_id');
    }
}
