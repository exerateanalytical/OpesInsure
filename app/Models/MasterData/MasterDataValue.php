<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use App\Models\MasterData\Concerns\ProtectsSeededMasterData;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MasterDataValue extends Model
{
    use HasUuids, ProtectsSeededMasterData;

    protected $table = 'master_data_values';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attributes' => 'array', 'is_seeded' => 'boolean', 'is_other' => 'boolean', 'is_common' => 'boolean', 'effective_from' => 'date', 'effective_until' => 'date', 'verified_at' => 'datetime', 'admin_modified_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $v): void {
            $aliases = $v->exists ? $v->aliases()->pluck('alias')->all() : [];
            $v->search_text = \App\Application\MasterData\MasterDataNormalizer::searchText([$v->code, $v->label_en, $v->label_fr, ...$aliases]);
        });
    }

    public function masterList(): BelongsTo
    {
        return $this->belongsTo(MasterDataList::class, 'list_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_value_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(MasterDataAlias::class, 'value_id');
    }

    public function label(string $locale): string
    {
        return str_starts_with($locale, 'fr') ? $this->label_fr : $this->label_en;
    }
}
