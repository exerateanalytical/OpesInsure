<?php

declare(strict_types=1);

namespace App\Application\Partners\Setup\Models;

use App\Models\Partner;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** REQ-SET-003 — broker setup lifecycle record (status only changes through PartnerSetupService). */
final class PartnerSetup extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['activation_snapshot' => 'array', 'submitted_for_activation_at' => 'datetime', 'activated_at' => 'datetime'];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
