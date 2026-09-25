<?php

declare(strict_types=1);

namespace App\Application\CarrierOperations\Setup\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REQ-SET-002/003 — manual attestation of a checklist item (shared by insurer and broker setup). */
final class SetupChecklistAttestation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }
}
