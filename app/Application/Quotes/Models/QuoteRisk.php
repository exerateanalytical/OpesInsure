<?php

declare(strict_types=1);

namespace App\Application\Quotes\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REQ-QUO-003: one quoted risk, referencing an insured object (risk_assets, REQ-RSK-001) when there is one. */
final class QuoteRisk extends Model
{
    use HasUuids;

    protected $fillable = ['quote_id', 'risk_asset_id', 'sequence', 'line_code', 'risk_type', 'facts', 'facts_hash'];

    protected function casts(): array
    {
        return ['facts' => 'array'];
    }
}
