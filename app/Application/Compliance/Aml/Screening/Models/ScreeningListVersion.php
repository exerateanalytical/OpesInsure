<?php

declare(strict_types=1);

namespace App\Application\Compliance\Aml\Screening\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Agent E8 — REQ-AML-001 imported version of a screening list (maker-checker before ACTIVE). */
final class ScreeningListVersion extends Model
{
    use HasUuids;

    protected $table = 'screening_list_versions';

    protected $guarded = [];

    protected $casts = ['decided_at' => 'datetime', 'activated_at' => 'datetime', 'version' => 'integer', 'entry_count' => 'integer'];
}
