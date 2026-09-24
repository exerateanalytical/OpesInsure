<?php

declare(strict_types=1);

namespace App\Models\Vehicles;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class VehicleMasterReview extends Model
{
    use HasUuids;

    protected $table = 'vehicle_master_review_queue';

    protected $guarded = ['id'];

    public const STATUS_PENDING = 'MASTER_DATA_REVIEW_REQUIRED';

    protected function casts(): array
    {
        return ['payload' => 'array', 'model_year' => 'integer', 'reviewed_at' => 'datetime'];
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function resolvedMake(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'resolved_make_id');
    }

    public function resolvedModel(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'resolved_model_id');
    }
}
