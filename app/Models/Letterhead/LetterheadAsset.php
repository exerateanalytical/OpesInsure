<?php

declare(strict_types=1);

namespace App\Models\Letterhead;

use App\Models\Carrier;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One immutable version of an insurer's or organisation's document letterhead (see migration 2026_10_13_100001). */
final class LetterheadAsset extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['public_display' => 'boolean', 'authorized_on' => 'date', 'approved_at' => 'datetime', 'version' => 'integer'];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return list<string> Legal footer lines, only those that are set (never invented). */
    public function footerLines(): array
    {
        return array_values(array_filter([
            $this->registered_address ? trim(preg_replace('/\s+/', ' ', (string) $this->registered_address)) : null,
            $this->rccm ? 'RCCM '.$this->rccm : null,
            $this->niu ? 'NIU '.$this->niu : null,
            $this->licence_reference ? $this->licence_reference : null,
        ]));
    }
}
