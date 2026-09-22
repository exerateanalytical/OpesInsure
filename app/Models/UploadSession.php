<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class UploadSession extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'user_id', 'resource_type', 'mime_type', 'total_chunks', 'total_size_bytes', 'status', 'expected_sha256', 'storage_key', 'expires_at'];

    protected function casts(): array
    {
        return ['total_chunks' => 'integer', 'total_size_bytes' => 'integer', 'expires_at' => 'datetime'];
    }
}
