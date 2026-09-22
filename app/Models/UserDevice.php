<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserDevice extends Model
{
    use HasUuids;
    protected $fillable = ['user_id', 'device_fingerprint', 'name', 'platform', 'last_seen_at', 'trusted_at', 'revoked_at', 'security_metadata'];
    protected function casts(): array { return ['last_seen_at'=>'datetime','trusted_at'=>'datetime','revoked_at'=>'datetime','security_metadata'=>'array']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
