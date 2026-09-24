<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use App\Models\MasterData\Concerns\ProtectsSeededMasterData;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MasterDataAlias extends Model
{
    use HasUuids, ProtectsSeededMasterData;

    protected $table = 'master_data_aliases';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_seeded' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $a) => $a->normalized = \App\Application\MasterData\MasterDataNormalizer::normalize($a->alias));
        // Keep the value's search text in step with its aliases.
        static::saved(fn (self $a) => $a->value?->touch());
    }

    public function value(): BelongsTo
    {
        return $this->belongsTo(MasterDataValue::class, 'value_id');
    }

    public function domainCodeForCache(): ?string
    {
        return $this->value?->domain_code;
    }
}
