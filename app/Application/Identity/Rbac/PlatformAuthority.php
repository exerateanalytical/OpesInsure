<?php

declare(strict_types=1);

namespace App\Application\Identity\Rbac;

use App\Application\Identity\RoleCatalogue;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who may act on the platform itself (tenant status, platform roles) and
 * which roles a user may hand out. Single home for the "is this the
 * platform tenant" check that controllers used to repeat.
 */
final class PlatformAuthority
{
    /** Roles that confer platform-wide power; only platform admins may grant them. */
    public const PLATFORM_TIER = ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'DEVELOPER', 'FINANCE_ADMIN'];

    private const PLATFORM_ADMINS = ['SYSTEM_ADMIN', 'PLATFORM_ADMIN'];

    public function __construct(private readonly TenantContext $context) {}

    public function isPlatformTenant(?string $tenantId = null): bool
    {
        return DB::table('tenants')->where('id', $tenantId ?? $this->context->id())->value('type') === 'PLATFORM';
    }

    /** An ACTIVE SYSTEM_ADMIN/PLATFORM_ADMIN membership in a PLATFORM-type tenant. */
    public function isPlatformAdmin(User $user): bool
    {
        return DB::table('tenant_memberships as m')->join('tenants as t', 't.id', '=', 'm.tenant_id')
            ->where('m.user_id', $user->getKey())->where('m.status', 'ACTIVE')->where('t.type', 'PLATFORM')
            ->whereIn('m.role_code', self::PLATFORM_ADMINS)->exists();
    }

    /**
     * Role ceiling for invitations and role grants into $tenantId.
     * Platform admins may grant any role anywhere, except that platform-tier
     * roles only exist inside the platform tenant. Anyone else may grant only
     * into their current tenant, never a platform-tier role, and only a role
     * whose default permissions they already hold there — so a '*' holder
     * (claims/finance managers) still cannot mint a platform admin.
     */
    public function assertMayGrant(User $actor, string $tenantId, string $roleCode): void
    {
        $platformTier = in_array($roleCode, self::PLATFORM_TIER, true);

        if ($this->isPlatformAdmin($actor)) {
            if ($platformTier && ! $this->isPlatformTenant($tenantId)) {
                throw ValidationException::withMessages(['role_code' => __('security.role_ceiling_platform')]);
            }

            return;
        }

        if ($platformTier) {
            throw ValidationException::withMessages(['role_code' => __('security.role_ceiling_platform')]);
        }

        $current = rescue(fn () => $this->context->id(), null, false);
        if ($tenantId !== $current) {
            throw ValidationException::withMessages(['tenant_id' => __('security.tenant_mismatch')]);
        }

        foreach (RoleCatalogue::defaultPermissions($roleCode) as $permission) {
            if (! $actor->hasPermission($permission)) {
                throw ValidationException::withMessages(['role_code' => __('security.role_ceiling', ['permission' => $permission])]);
            }
        }
    }
}
