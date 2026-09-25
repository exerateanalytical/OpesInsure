<?php

declare(strict_types=1);

namespace App\Application\Identity\Rbac;

use App\Application\Identity\RoleCatalogue;
use App\Domain\Tenancy\TenantContext;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The single permission check behind User::hasPermission() (REQ-RBAC-001/004).
 *
 *  - Platform permissions (not business data): an active SYSTEM_ADMIN
 *    membership still passes, as before, so the admin panel and platform
 *    operations are unchanged.
 *  - Everyone: the permission, the module wildcard ("claims.*") or '*' on a
 *    role attached to an ACTIVE membership in the CURRENT tenant.
 *  - Business-data permissions (PermissionCatalogue::isBusinessData) are
 *    never granted by a PLATFORM_ONLY role (SYSTEM_ADMIN, DEVELOPER) — not
 *    by '*', not by an explicit string. Platform admins reach business data
 *    only through an approved, audited break-glass grant (BreakGlass).
 */
final class PermissionEvaluator
{
    public function __construct(private readonly TenantContext $context) {}

    public function allows(User $user, string $permission): bool
    {
        $business = PermissionCatalogue::isBusinessData($permission);

        if (! $business && $user->memberships()->where('status', 'ACTIVE')->where('role_code', 'SYSTEM_ADMIN')->exists()) {
            return true;
        }

        $tenantId = $this->context->id();
        $wanted = [$permission, '*', Str::before($permission, '.').'.*'];

        $memberships = TenantMembership::query()
            ->where('user_id', $user->getKey())
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->with('roles:id,permissions')
            ->get(['id', 'role_code']);

        foreach ($memberships as $m) {
            if ($business && RoleCatalogue::isPlatformOnly((string) $m->role_code)) {
                continue;
            }
            foreach ($m->roles as $role) {
                if (array_intersect($wanted, (array) $role->permissions) !== []) {
                    return true;
                }
            }
        }

        return $business && app(BreakGlass::class)->allows($user, $tenantId, $permission, $this);
    }
}
