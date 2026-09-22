<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class VerificationChallenge extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'purpose', 'channel', 'destination_hash', 'code_hash', 'attempts', 'max_attempts', 'expires_at', 'consumed_at', 'request_ip_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
