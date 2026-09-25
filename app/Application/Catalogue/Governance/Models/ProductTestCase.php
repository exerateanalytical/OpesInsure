<?php

declare(strict_types=1);

namespace App\Application\Catalogue\Governance\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REQ-PRD-008 — one case of a version's test policy pack. */
final class ProductTestCase extends Model
{
    use HasUuids;

    protected $fillable = ['insurance_product_id', 'code', 'name', 'facts', 'expected', 'reference_date', 'created_by'];

    protected function casts(): array
    {
        return ['facts' => 'array', 'expected' => 'array', 'reference_date' => 'date'];
    }
}
