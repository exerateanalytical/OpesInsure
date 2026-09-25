<?php

declare(strict_types=1);

namespace App\Application\Kyc\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class KycLevelRequirement extends Model
{
    use HasUuids;

    protected $table = 'kyc_level_requirements';

    protected $guarded = [];

    protected $casts = ['accepted_canonical_codes' => 'array', 'mandatory' => 'boolean'];
}
