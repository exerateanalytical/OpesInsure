<?php

declare(strict_types=1);

namespace App\Models\Regulatory;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Owner decision item 8: a product already live when the CIMA gate went on, whose insurer authorization is not yet verified. */
final class LegacyProductAuthorization extends Model
{
    use HasUuids;

    public const STATUS = 'LEGACY_ACTIVE_AUTHORIZATION_PENDING_VERIFICATION';

    protected $table = 'legacy_product_authorizations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['branch_codes' => 'array', 'recorded_at' => 'datetime', 'resolved_at' => 'datetime'];
    }
}
