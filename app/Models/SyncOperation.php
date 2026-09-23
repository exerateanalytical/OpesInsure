<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SyncOperation extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'user_id', 'operation_uuid', 'kind', 'resource', 'resource_id',
        'method', 'path', 'status', 'server_version', 'payload', 'response_body', 'error_code',
        'attempt_count', 'synchronized_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'response_body' => 'array', 'synchronized_at' => 'datetime'];
    }
}
