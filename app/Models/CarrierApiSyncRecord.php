<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** What one of our records (policy, payment) must send to a carrier API, and the carrier's identifiers once it has. */
final class CarrierApiSyncRecord extends Model
{
    use HasUuids;

    public const OPEN = ['PENDING', 'RETRY_PENDING', 'CONFIG_REQUIRED'];

    public const FAILURES = ['RETRY_PENDING', 'FAILED', 'MAPPING_REQUIRED'];

    protected $table = 'carrier_api_sync_records';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['external_data' => 'array', 'attempts' => 'integer', 'next_attempt_at' => 'datetime', 'last_attempt_at' => 'datetime', 'synced_at' => 'datetime'];
    }
}
