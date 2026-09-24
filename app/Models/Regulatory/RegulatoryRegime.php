<?php

declare(strict_types=1);

namespace App\Models\Regulatory;

use App\Models\Regulatory\Concerns\ProtectsSeededRegulatoryData;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class RegulatoryRegime extends Model
{
    use HasUuids, ProtectsSeededRegulatoryData;

    protected $table = 'regulatory_regimes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'is_seeded' => 'boolean', 'metadata' => 'array'];
    }
}
