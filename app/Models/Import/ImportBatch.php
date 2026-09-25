<?php

declare(strict_types=1);

namespace App\Models\Import;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REQ-IMP-001 one generic import batch (any ImportTarget). Never deleted: it is the audit trail of the import. */
final class ImportBatch extends Model
{
    use HasUuids;

    protected $table = 'import_batches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['target_params' => 'array', 'source_columns' => 'array', 'mapping' => 'array', 'raw_rows' => 'array', 'rows' => 'array',
            'report' => 'array', 'result' => 'array', 'imported_at' => 'datetime', 'imported_count' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Import batches are audit records and cannot be deleted; cancel instead.'));
    }
}
