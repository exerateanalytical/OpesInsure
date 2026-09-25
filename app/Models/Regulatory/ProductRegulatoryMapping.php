<?php

declare(strict_types=1);

namespace App\Models\Regulatory;

use App\Models\Regulatory\Concerns\ProtectsSeededRegulatoryData;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use App\Models\InsuranceProduct;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class ProductRegulatoryMapping extends Model
{
    use HasUuids, ProtectsSeededRegulatoryData;

    protected $table = 'product_regulatory_mappings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'is_seeded' => 'boolean', 'is_primary' => 'boolean', 'product_version' => 'integer', 'approved_at' => 'datetime', 'separate_premium' => 'boolean', 'conditions' => 'array'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InsuranceProduct::class, 'insurance_product_id');
    }
}
