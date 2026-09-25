<?php

declare(strict_types=1);

namespace App\Models\Catalogue;

use App\Models\Carrier;
use App\Models\InsuranceProduct;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** REQ-PRD-001/003 — stable carrier product identity; its versions are insurance_products rows. */
final class CarrierProduct extends Model
{
    use HasUuids;

    public const CUSTOMER_TYPES = ['INDIVIDUAL', 'FAMILY', 'SME', 'CORPORATE', 'GROUP', 'GOVERNMENT', 'ASSOCIATION'];

    protected $fillable = ['carrier_id', 'product_family_id', 'code', 'line_code', 'name', 'description', 'customer_type', 'currency', 'market', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['name' => 'array', 'description' => 'array'];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'product_family_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(InsuranceProduct::class)->orderBy('version');
    }
}
