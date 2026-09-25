<?php

declare(strict_types=1);

namespace App\Application\Compliance\Aml\Screening\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Agent E8 — REQ-AML-001 entry of a screening list version. */
final class ScreeningListEntry extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'screening_list_entries';

    protected $guarded = [];

    protected $casts = ['aliases' => 'array', 'normalized_names' => 'array', 'attributes' => 'array', 'date_of_birth' => 'date:Y-m-d'];
}
