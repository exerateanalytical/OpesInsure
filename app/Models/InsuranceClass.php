<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ProtectsOfficialRegister;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Insurance class taxonomy (IARD classes / sub-classes, LIFE families) from the official register. */
final class InsuranceClass extends Model
{
    use HasUuids, ProtectsOfficialRegister;

    protected $fillable = ['parent_id', 'branch', 'code', 'name', 'sort_order', 'data_origin', 'source_authority', 'reference_year', 'register_source', 'is_official_register'];

    protected function casts(): array
    {
        return ['name' => 'array', 'is_official_register' => 'boolean', 'reference_year' => 'integer', 'sort_order' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }
}
