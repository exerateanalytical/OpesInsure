<?php

declare(strict_types=1);

namespace App\Application\Compliance\Aml\Screening\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Agent E8 — REQ-AML-001 screening list source (PEP / SANCTIONS / WATCHLIST) of a tenant. */
final class ScreeningListSource extends Model
{
    use HasUuids;

    protected $table = 'screening_list_sources';

    protected $guarded = [];
}
