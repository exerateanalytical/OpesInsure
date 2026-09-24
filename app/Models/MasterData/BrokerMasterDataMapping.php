<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BrokerMasterDataMapping extends Model
{
    use HasUuids;

    protected $table = 'broker_master_data_mappings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [];
    }

    public function value(): BelongsTo
    {
        return $this->belongsTo(MasterDataValue::class, 'value_id');
    }
}
