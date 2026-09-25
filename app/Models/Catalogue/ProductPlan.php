<?php

declare(strict_types=1);

namespace App\Models\Catalogue;

use App\Models\CoverageDefinition;
use App\Models\InsuranceProduct;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** REQ-PRD-004 — plan / package of a product version (TP, TP+, Bronze…Platinum). */
final class ProductPlan extends Model
{
    use HasUuids;

    public const TIERS = ['TP', 'TP_PLUS', 'INTERMEDIATE', 'COMPREHENSIVE', 'BRONZE', 'SILVER', 'GOLD', 'PLATINUM', 'CUSTOM'];

    protected $fillable = ['insurance_product_id', 'code', 'name', 'description', 'tier', 'tariff_version_id', 'pricing_reference', 'eligibility', 'is_default', 'display_order', 'status'];

    protected function casts(): array
    {
        return ['name' => 'array', 'description' => 'array', 'eligibility' => 'array', 'is_default' => 'boolean'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(InsuranceProduct::class, 'insurance_product_id');
    }

    public function coverages(): BelongsToMany
    {
        return $this->belongsToMany(CoverageDefinition::class, 'product_plan_coverages')->withPivot(['inclusion', 'display_order'])->orderByPivot('display_order');
    }

    public function limits(): HasMany
    {
        return $this->hasMany(CoverageLimit::class);
    }

    public function deductibles(): HasMany
    {
        return $this->hasMany(CoverageDeductible::class);
    }
}
