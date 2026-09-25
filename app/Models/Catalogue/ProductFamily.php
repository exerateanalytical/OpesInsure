<?php

declare(strict_types=1);

namespace App\Models\Catalogue;

use App\Models\InsuranceClass;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** REQ-PRD-002 — product family: CIMA branch → class → family → carrier product. */
final class ProductFamily extends Model
{
    use HasUuids;

    public const CLASS_CODES = ['MOTOR', 'HEALTH', 'PERSONAL_ACCIDENT', 'PROPERTY', 'HOME', 'FIRE', 'TRAVEL', 'LIABILITY', 'PROFESSIONAL_LIABILITY',
        'BUSINESS_MULTIRISK', 'CONSTRUCTION', 'ENGINEERING', 'MARINE', 'TRANSPORT', 'CARGO', 'AVIATION', 'CREDIT', 'SURETY', 'AGRICULTURE', 'LIVESTOCK',
        'ASSISTANCE', 'LEGAL_PROTECTION', 'FINANCIAL_LOSS', 'LIFE', 'DEATH', 'SAVINGS', 'CAPITALIZATION', 'RETIREMENT', 'EDUCATION', 'CREDIT_LIFE',
        'GROUP_LIFE', 'PROVIDENT', 'FUNERAL', 'ANNUITY'];

    protected $fillable = ['code', 'class_code', 'insurance_class_id', 'line_code', 'default_branch_code', 'name', 'description', 'status'];

    protected function casts(): array
    {
        return ['name' => 'array', 'description' => 'array'];
    }

    public function insuranceClass(): BelongsTo
    {
        return $this->belongsTo(InsuranceClass::class);
    }

    public function carrierProducts(): HasMany
    {
        return $this->hasMany(CarrierProduct::class);
    }
}
