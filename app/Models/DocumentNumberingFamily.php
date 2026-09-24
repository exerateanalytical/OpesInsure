<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Numbering family (POL, ATT-MOT, AVN, CLM, RCT, SET …): platform default when tenant_id is null, tenant override otherwise. */
final class DocumentNumberingFamily extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'family_code', 'prefix', 'include_year', 'pad', 'document_type_codes', 'status'];

    protected function casts(): array
    {
        return ['document_type_codes' => 'array', 'include_year' => 'boolean'];
    }
}
