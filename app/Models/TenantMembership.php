<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantMembership extends Model
{
    use HasUuids;
    protected $fillable = ['tenant_id', 'user_id', 'branch_id', 'carrier_id', 'role_code', 'status', 'suspended_at', 'revoked_at', 'revoked_by', 'revocation_reason'];
    protected function casts(): array { return ['suspended_at'=>'datetime','revoked_at'=>'datetime']; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function branch(): BelongsTo { return $this->belongsTo(TenantBranch::class, 'branch_id'); }
    public function roles(): BelongsToMany { return $this->belongsToMany(Role::class, 'membership_roles', 'membership_id', 'role_id'); }
}
