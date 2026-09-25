<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\HasApiTokens;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable implements FilamentUser, HasName
{
    use HasApiTokens, HasFactory, HasUuids;
    protected $fillable = ['full_name', 'email', 'phone_e164', 'party_id', 'password', 'locale', 'status'];
    protected $hidden = ['password', 'remember_token'];
    protected function casts(): array { return ['password' => 'hashed', 'email_verified_at' => 'datetime', 'phone_verified_at' => 'datetime', 'notification_preferences' => 'array']; }
    public function memberships(): HasMany { return $this->hasMany(TenantMembership::class); }
    public function devices(): HasMany { return $this->hasMany(UserDevice::class); }
    public function mfaMethods(): HasMany { return $this->hasMany(MfaMethod::class); }
    public function party(): BelongsTo { return $this->belongsTo(Party::class); }
    public function canAccessPanel(Panel $panel): bool { return $this->status === 'ACTIVE' && $this->memberships()->where('status', 'ACTIVE')->whereIn('role_code', ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'FINANCE_ADMIN', 'FINANCE_MANAGER', 'CLAIMS_MANAGER', 'CLAIMS_OFFICER', 'BROKER_STAFF', 'AGENT'])->exists(); }
    public function getFilamentName(): string { return $this->full_name; }

    /**
     * Ability check used by RequirePermission and the wave 10/11 policies.
     * Delegates to App\Application\Identity\Rbac\PermissionEvaluator:
     * SYSTEM_ADMIN passes platform permissions only; business-data
     * permissions need a business role in the current tenant or an approved,
     * audited break-glass grant (REQ-RBAC-004).
     */
    public function hasPermission(string $permission): bool
    {
        return app(\App\Application\Identity\Rbac\PermissionEvaluator::class)->allows($this, $permission);
    }
}
