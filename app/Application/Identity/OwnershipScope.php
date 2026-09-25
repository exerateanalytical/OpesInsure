<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Row-level ownership for the core (non-/mobile) API routes.
 *
 * Every self-registered customer shares one tenant, so tenant scoping alone
 * lets customer A read customer B's records (audit 2026-09-24, A1). A caller
 * whose only active role in the current tenant is CUSTOMER is "owner
 * scoped": every query is narrowed to their own party, and a foreign record
 * is indistinguishable from a missing one (404). Any other role (staff,
 * partner, platform admin) keeps tenant-wide access, gated by permissions
 * where the route asks for them via requireTenantWide().
 */
final class OwnershipScope
{
    public function __construct(private PartyResolver $parties, private TenantContext $context) {}

    public function isOwnerScoped(User $user): bool
    {
        // REQ-TEN-003 / REQ-RBAC-004: only memberships in the CURRENT tenant
        // count. A SYSTEM_ADMIN membership elsewhere no longer lifts owner
        // scoping here, and a platform admin inside this tenant is still gated
        // by business-data permissions in requireTenantWide().
        return ! $user->memberships()
            ->where('tenant_id', $this->context->id())
            ->where('status', 'ACTIVE')
            ->where('role_code', '!=', 'CUSTOMER')
            ->exists();
    }

    /**
     * REQ-RBAC-004: a caller whose only roles in this tenant are platform
     * administration roles (SYSTEM_ADMIN, DEVELOPER) sees no business rows,
     * unless a break-glass grant covers customers.read (audited by BreakGlass).
     */
    public function isPlatformOnly(User $user): bool
    {
        $roles = $user->memberships()->where('tenant_id', $this->context->id())->where('status', 'ACTIVE')->pluck('role_code');

        return $roles->isNotEmpty()
            && $roles->every(fn ($r) => RoleCatalogue::isPlatformOnly((string) $r))
            && ! $user->hasPermission('customers.read');
    }

    public function partyId(User $user): ?string
    {
        return $this->parties->forUser($user)?->id;
    }

    /** Narrow $query to the caller's own party when they are owner scoped. */
    public function apply(Builder $query, User $user, string $column = 'party_id'): Builder
    {
        if (! $this->isOwnerScoped($user)) {
            return $this->isPlatformOnly($user) ? $query->whereRaw('1 = 0') : $query;
        }
        $partyId = $this->partyId($user);

        return $partyId ? $query->where($column, $partyId) : $query->whereRaw('1 = 0');
    }

    /** Same as apply(), for records owned through a relation (e.g. payment → proposal). */
    public function applyVia(Builder $query, User $user, string $relation, string $column = 'party_id'): Builder
    {
        if (! $this->isOwnerScoped($user)) {
            return $this->isPlatformOnly($user) ? $query->whereRaw('1 = 0') : $query;
        }
        $partyId = $this->partyId($user);

        return $partyId ? $query->whereHas($relation, fn ($q) => $q->where($column, $partyId)) : $query->whereRaw('1 = 0');
    }

    /** 404 unless an owner-scoped caller is acting for their own party. */
    public function assertOwnParty(User $user, ?string $partyId): void
    {
        if ($this->isOwnerScoped($user) && ($partyId === null || $partyId !== $this->partyId($user))) {
            abort(404);
        }
        if (! $this->isOwnerScoped($user) && $this->isPlatformOnly($user)) {
            abort(404);
        }
    }

    /** Tenant-wide listings need an explicit read permission for non-customers. */
    public function requireTenantWide(User $user, string $permission): void
    {
        if (! $this->isOwnerScoped($user) && ! $user->hasPermission($permission)) {
            abort(403, 'Permission denied.');
        }
    }
}
