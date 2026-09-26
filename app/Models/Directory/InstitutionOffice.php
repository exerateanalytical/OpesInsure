<?php

declare(strict_types=1);

namespace App\Models\Directory;

use App\Models\Carrier;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Institutional office of an official insurer: HEAD_OFFICE or DIRECT_BRANCH. */
final class InstitutionOffice extends Model
{
    use HasUuids;

    public const TYPES = ['HEAD_OFFICE', 'DIRECT_BRANCH'];

    protected $fillable = ['carrier_id', 'office_type', 'name', 'city', 'address', 'phone', 'sort_order', 'source', 'partner_id'];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }
}
