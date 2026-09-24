<?php

declare(strict_types=1);

namespace App\Models\Regulatory;

use App\Models\Regulatory\Concerns\ProtectsSeededRegulatoryData;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
final class RegulatoryTerm extends Model
{
    use HasUuids, ProtectsSeededRegulatoryData;

    protected $table = 'regulatory_terms';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'is_seeded' => 'boolean', 'preferred' => 'boolean'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(RegulatoryTermTranslation::class);
    }

    public function label(string $locale): ?string
    {
        $rows = $this->relationLoaded('translations') ? $this->translations : $this->translations()->get();

        return $rows->where('locale', $locale)->sortBy(fn ($t) => $t->context === 'PREFERRED' ? 0 : 1)->first()?->label;
    }
}
