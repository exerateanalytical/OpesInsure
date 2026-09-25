<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** REQ-MDM-007 maker-checker merge of two master-data values (approval action entity.merge). */
final class MasterDataMergeRequest extends Model
{
    use HasUuids;

    protected $table = 'master_data_merge_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(MasterDataValue::class, 'from_value_id');
    }

    public function into(): BelongsTo
    {
        return $this->belongsTo(MasterDataValue::class, 'into_value_id');
    }
}
