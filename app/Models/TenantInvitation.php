<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TenantInvitation extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'recipient_email', 'recipient_phone_e164', 'role_code', 'token_hash', 'status', 'expires_at', 'invited_by', 'accepted_at'];
    protected $hidden = ['token_hash'];
    protected function casts(): array { return ['expires_at' => 'datetime', 'accepted_at' => 'datetime']; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function inviter(): BelongsTo { return $this->belongsTo(User::class, 'invited_by'); }
}
