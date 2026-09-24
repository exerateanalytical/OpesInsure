<?php

declare(strict_types=1);

namespace App\Models\Regulatory;

use App\Models\Regulatory\Concerns\ProtectsSeededRegulatoryData;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class InsurerAuthorizedBranch extends Model
{
    use HasUuids, ProtectsSeededRegulatoryData;

    protected $table = 'insurer_authorized_branches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'is_seeded' => 'boolean'];
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(InsurerRegulatoryAuthorization::class, 'authorization_id');
    }
}
