<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MasterDataReviewItem extends Model
{
    use HasUuids;

    protected $table = 'master_data_review_queue';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['possible_duplicates' => 'array', 'submissions' => 'array', 'resolved_at' => 'datetime'];
    }

    public const STATUSES = ['SUBMITTED', 'UNDER_REVIEW', 'DUPLICATE_FOUND', 'APPROVED', 'MERGED', 'REJECTED', 'ARCHIVED'];

    public const OPEN = ['SUBMITTED', 'UNDER_REVIEW', 'DUPLICATE_FOUND'];

    public function masterList(): BelongsTo
    {
        return $this->belongsTo(MasterDataList::class, 'list_id');
    }

    public function resolvedValue(): BelongsTo
    {
        return $this->belongsTo(MasterDataValue::class, 'resolved_value_id');
    }
}
