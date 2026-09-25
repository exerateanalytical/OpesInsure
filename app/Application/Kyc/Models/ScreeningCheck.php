<?php

declare(strict_types=1);

namespace App\Application\Kyc\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ScreeningCheck extends Model
{
    use HasUuids;

    protected $table = 'screening_checks';

    protected $guarded = [];

    protected $casts = ['result' => 'array', 'checked_at' => 'datetime'];
}
