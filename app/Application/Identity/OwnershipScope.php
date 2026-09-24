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
 * partner, SYSTEM_ADMIN) keeps tenant-wide access, gated by permissions
 * where the route asks for them via requireTenantWide().
 */
final class OwnershipScope
{
    public function __construct(private PartyResolver $parties, private TenantContext $context) {}

    public function isOwnerScoped(User $user): bool
    {
        if ($user->memberships()->where('status', 'ACTIVE')->where('role_code', 'SYSTEM_ADMIN')->exists()) {
            return false;
        }

        return ! $user->memberships()
            ->where('tenant_id', $this->context->id())
            ->where('status', 'ACTIVE')
            ->where('role_code', '!=', 'CUSTOMER')
            ->exists();
    }

    public function partyId(User $user): ?string
    {
        return $this->parties->forUser($user)?->id;
    }

    /** Narrow $query to the caller's own party when they are owner scoped. */
    public function apply(Builder $query, User $user, string $column = 'party_id'): Builder
    {
        if (! $this->isOwnerScoped($user)) {
            return $query;
        }
        $partyId = $this->partyId($user);

        return $partyId ? $query->where($column, $partyId) : $query->whereRaw('1 = 0');
    }

    /** Same as apply(), for records owned through a relation (e.g. payment → proposal). */
    public function applyVia(Builder $query, User $user, string $relation, string $column = 'party_id'): Builder
    {
        if (! $this->isOwnerScoped($user)) {
            return $query;
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
    }

    /** Tenant-wide listings need an explicit read permission for non-customers. */
    public function requireTenantWide(User $user, string $permission): void
    {
        if (! $this->isOwnerScoped($user) && ! $user->hasPermission($permission)) {
            abort(403, 'Permission denied.');
        }
    }
}
