<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use App\Models\MasterData\Concerns\ProtectsSeededMasterData;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MasterDataDomain extends Model
{
    use HasUuids, ProtectsSeededMasterData;

    protected $table = 'master_data_domains';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_seeded' => 'boolean', 'catalog_version' => 'integer'];
    }

    public function lists(): HasMany
    {
        return $this->hasMany(MasterDataList::class, 'domain_id');
    }

    public function domainCodeForCache(): ?string
    {
        return $this->code;
    }
}
