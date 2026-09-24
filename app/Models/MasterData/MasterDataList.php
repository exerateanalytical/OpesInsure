<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use App\Models\MasterData\Concerns\ProtectsSeededMasterData;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MasterDataList extends Model
{
    use HasUuids, ProtectsSeededMasterData;

    protected $table = 'master_data_lists';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_seeded' => 'boolean', 'allow_other' => 'boolean', 'structure_only' => 'boolean', 'effective_from' => 'date', 'effective_until' => 'date'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(MasterDataDomain::class, 'domain_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(MasterDataValue::class, 'list_id');
    }
}
