<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ProtectsOfficialRegister;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Effective-dated authorization from a year's official register; never overwritten across years. */
final class InsurerAuthorization extends Model
{
    use HasUuids, ProtectsOfficialRegister;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'is_official_register' => 'boolean', 'reference_year' => 'integer'];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }
}
