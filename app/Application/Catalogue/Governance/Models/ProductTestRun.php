<?php

declare(strict_types=1);

namespace App\Application\Catalogue\Governance\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REQ-PRD-008 — sandbox evidence (governance record, not a business record). Immutable once written. */
final class ProductTestRun extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['insurance_product_id', 'configuration_hash', 'status', 'cases_total', 'cases_failed', 'results', 'run_by', 'ran_at'];

    protected function casts(): array
    {
        return ['results' => 'array', 'ran_at' => 'datetime'];
    }
}
