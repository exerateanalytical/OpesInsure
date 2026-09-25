<?php

namespace App\Models;

use App\Models\Catalogue\CarrierProduct;
use App\Models\Catalogue\CoverageDeductible;
use App\Models\Catalogue\CoverageLimit;
use App\Models\Catalogue\ProductPlan;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product VERSION (REQ-PRD-001). The stable identity is CarrierProduct; the
 * blueprint name `product_versions` is a read-only view over this table.
 * Storage status IN_REVIEW = REVIEW and ACTIVE = PUBLISHED (see ProductVersionStatus).
 */
final class InsuranceProduct extends Model
{
    use HasUuids;

    protected $fillable = ['carrier_id', 'line_code', 'code', 'name', 'version', 'effective_from', 'effective_until', 'status', 'coverages', 'eligibility_rules',
        'created_by', 'published_by', 'published_at', 'regulatory_reference', 'carrier_product_id', 'base_version_id', 'sales_start', 'sales_end',
        'new_business_allowed', 'renewal_allowed', 'approved_by', 'approved_at', 'suspended_at', 'suspension_reason', 'retired_at', 'snapshot', 'snapshot_hash'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'coverages' => 'array', 'eligibility_rules' => 'array', 'published_at' => 'datetime',
            'sales_start' => 'date', 'sales_end' => 'date', 'new_business_allowed' => 'boolean', 'renewal_allowed' => 'boolean', 'approved_at' => 'datetime',
            'suspended_at' => 'datetime', 'retired_at' => 'datetime', 'snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        // Every version belongs to a carrier product (REQ-PRD-001); legacy callers creating
        // versions directly get the identity row attached automatically.
        self::creating(function (self $p): void {
            if ($p->carrier_product_id === null && $p->carrier_id && $p->code) {
                $p->carrier_product_id = CarrierProduct::firstOrCreate(
                    ['carrier_id' => $p->carrier_id, 'code' => $p->code],
                    ['line_code' => $p->line_code, 'name' => ['en' => $p->name, 'fr' => $p->name], 'currency' => 'XAF', 'status' => 'ACTIVE', 'created_by' => $p->created_by],
                )->id;
            }
        });
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function carrierProduct(): BelongsTo
    {
        return $this->belongsTo(CarrierProduct::class);
    }

    public function coverageDefinitions(): BelongsToMany
    {
        return $this->belongsToMany(CoverageDefinition::class, 'product_coverages')
            ->withPivot(['default_limit_minor', 'default_deductible_minor', 'configuration', 'is_optional', 'display_order', 'inclusion', 'waiting_period_days', 'territory', 'coverage_period']);
    }

    public function exclusions(): BelongsToMany
    {
        return $this->belongsToMany(ExclusionDefinition::class, 'product_exclusions')->withPivot(['id', 'configuration', 'level', 'product_plan_id', 'coverage_definition_id', 'condition']);
    }

    public function tariffs(): HasMany
    {
        return $this->hasMany(TariffVersion::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(ProductPlan::class)->orderBy('display_order');
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
