<?php

declare(strict_types=1);

namespace App\Models\Regulatory;

use App\Models\Regulatory\Concerns\ProtectsSeededRegulatoryData;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class RegulatoryAuthority extends Model
{
    use HasUuids, ProtectsSeededRegulatoryData;

    protected $table = 'regulatory_authorities';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'is_seeded' => 'boolean'];
    }
}
