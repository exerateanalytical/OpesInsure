<?php

declare(strict_types=1);

namespace App\Application\Kyc\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Owner decision 27 — append-only customer risk assessment of a KYC submission (latest version is current). */
final class KycRiskAssessment extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'kyc_risk_assessments';

    protected $guarded = [];

    protected $casts = ['inputs' => 'array', 'factors' => 'array', 'triggers' => 'array', 'beneficial_ownership' => 'array',
        'source_of_funds' => 'array', 'source_of_wealth' => 'array', 'configuration_gaps' => 'array', 'edd_required' => 'boolean',
        'source_of_funds_required' => 'boolean', 'source_of_wealth_required' => 'boolean', 'score' => 'float', 'next_rescreen_at' => 'datetime'];
}
