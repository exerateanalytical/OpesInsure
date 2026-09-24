<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MasterDataImport extends Model
{
    use HasUuids;

    protected $table = 'master_data_imports';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mapping' => 'array', 'rows' => 'array', 'report' => 'array', 'imported_at' => 'datetime'];
    }
}
