<?php

declare(strict_types=1);

namespace App\Models\DocumentSecurity;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Owner-recorded physical security asset (Security Matrix §4 / §6; work item D6). Only VERIFIED rows count. */
final class PhysicalSecurityAsset extends Model
{
    use HasUuids;

    protected $table = 'document_physical_security_assets';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['applies_to_spec_ids' => 'array', 'verified_at' => 'datetime', 'serial_from' => 'integer', 'serial_to' => 'integer',
            'quantity_received' => 'integer', 'quantity_issued' => 'integer', 'quantity_spoiled' => 'integer', 'quantity_destroyed' => 'integer'];
    }

    /** Remaining custody balance (received − issued − spoiled − destroyed). */
    public function balance(): int
    {
        return $this->quantity_received - $this->quantity_issued - $this->quantity_spoiled - $this->quantity_destroyed;
    }
}
