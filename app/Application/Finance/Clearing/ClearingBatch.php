<?php

declare(strict_types=1);

namespace App\Application\Finance\Clearing;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One provider settlement batch in mobile-money clearing (REQ-PAY-011). */
final class ClearingBatch extends Model
{
    use HasUuids;

    protected $table = 'mobile_money_clearing_batches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settlement_date' => 'date:Y-m-d', 'expected_minor' => 'integer', 'fee_minor' => 'integer', 'settled_minor' => 'integer', 'variance_minor' => 'integer',
            'settled_at' => 'datetime', 'reconciled_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClearingItem::class, 'clearing_batch_id');
    }
}
