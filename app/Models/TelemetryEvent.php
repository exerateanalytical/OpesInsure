<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class TelemetryEvent extends Model
{
    use HasUuids;

    /**
     * Append-only event log: a telemetry row is written once and never
     * updated, so the table carries created_at alone (set explicitly by
     * MobileTelemetryService) and no updated_at for Eloquent to maintain.
     */
    public $timestamps = false;

    protected $fillable = ['event_name', 'correlation_id', 'app_version', 'release_channel', 'attributes', 'ip_hash', 'created_at'];

    protected function casts(): array
    {
        return ['attributes' => 'array', 'created_at' => 'datetime'];
    }
}
