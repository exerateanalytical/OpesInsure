<?php

declare(strict_types=1);

namespace App\Application\CarrierOperations\Setup\Models;

use App\Models\Carrier;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** REQ-SET-002 — insurer setup lifecycle record (status only changes through CarrierSetupService). */
final class CarrierSetup extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['activation_snapshot' => 'array', 'submitted_for_approval_at' => 'datetime', 'approved_at' => 'datetime', 'activated_at' => 'datetime'];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }
}
