<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Backs the generic idempotency-replay mechanism — see
 * App\Interfaces\Http\Middleware\IdempotencyGuard, which is the only thing
 * that should normally write to this table. One row per
 * (tenant_id, user_id, key, operation): the table's own unique constraint,
 * not application code, is what makes concurrent claims of the same key
 * safe (see IdempotencyGuard::claim()). response_status/response_body stay
 * null while a request is still being processed under that key, and are
 * filled in once it completes.
 */
final class IdempotencyKey extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'user_id', 'key', 'operation', 'request_hash', 'response_status', 'response_body', 'expires_at'];

    protected function casts(): array
    {
        return ['response_body' => 'array', 'expires_at' => 'datetime'];
    }
}
