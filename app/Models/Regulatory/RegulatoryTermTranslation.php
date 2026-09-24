<?php

declare(strict_types=1);

namespace App\Models\Regulatory;

use App\Models\Regulatory\Concerns\ProtectsSeededRegulatoryData;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class RegulatoryTermTranslation extends Model
{
    use HasUuids, ProtectsSeededRegulatoryData;

    protected $table = 'regulatory_term_translations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_seeded' => 'boolean'];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(RegulatoryTerm::class, 'regulatory_term_id');
    }
}
