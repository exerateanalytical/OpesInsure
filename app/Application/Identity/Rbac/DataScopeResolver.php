<?php

declare(strict_types=1);

namespace App\Application\Identity\Rbac;

use App\Application\Identity\PartyResolver;
use App\Application\Identity\RoleCatalogue;
use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * REQ-RBAC-002 / REQ-TEN-003: resolves the caller's effective data scope in
 * the current tenant and narrows a query to it. Fail-closed everywhere:
 *   - the tenant filter is ALWAYS applied (strict tenant isolation);
 *   - a scope whose column the caller did not declare yields no rows;
 *   - PLATFORM and REGULATOR_READ yield no business rows (REQ-RBAC-004).
 *
 * Column map keys: tenant (default tenant_id), own (party id), assigned
 * (user id of the assignee/producer), team (same column as assigned — team
 * members' user ids), branch, organization (partner id), carrier.
 */
final class DataScopeResolver
{
    public function __construct(private readonly TenantContext $context, private readonly PartyResolver $parties) {}

    /** @return Collection<int, TenantMembership> */
    private function memberships(User $user, string $tenantId): Collection
    {
        return TenantMembership::query()->where('user_id', $user->getKey())->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->with('roles')->get();
    }

    public function scopeOf(TenantMembership $m): DataScope
    {
        $override = $m->roles->pluck('data_scope')->filter()->map(fn ($s) => DataScope::tryFrom((string) $s))->filter()
            ->sortByDesc(fn (DataScope $s) => $s->rank())->first();

        return $override ?? RoleCatalogue::defaultScope((string) $m->role_code);
    }

    /** Widest business scope the caller holds in the tenant, or null (no membership). */
    public function effectiveScope(User $user, ?string $tenantId = null): ?DataScope
    {
        $scopes = $this->memberships($user, $tenantId ?? $this->context->id())->map(fn ($m) => $this->scopeOf($m));
        if ($scopes->isEmpty()) {
            return null;
        }

        return $scopes->sortByDesc(fn (DataScope $s) => $s->rank())->first();
    }

    /** @param array<string,string> $columns */
    public function apply(Builder $query, User $user, array $columns = []): Builder
    {
        $tenantId = $this->context->id();
        $query->where($columns['tenant'] ?? 'tenant_id', $tenantId);

        $memberships = $this->memberships($user, $tenantId);
        $scope = $memberships->map(fn ($m) => $this->scopeOf($m))->sortByDesc(fn (DataScope $s) => $s->rank())->first();

        $deny = fn () => $query->whereRaw('1 = 0');
        $col = fn (string $k) => $columns[$k] ?? null;

        switch ($scope) {
            case DataScope::TENANT:
                return $query;
            case DataScope::CARRIER_RELATIONSHIP:
                $carrierIds = $memberships->pluck('carrier_id')->filter()->unique()->values()->all();
                if ($carrierIds !== []) {
                    return $col('carrier') ? $query->whereIn($col('carrier'), $carrierIds) : $deny();
                }

                // An unlinked insurer role is tenant-wide only inside an insurer's own tenant.
                return Tenant::whereKey($tenantId)->value('type') === 'CARRIER' ? $query : $deny();
            case DataScope::ORGANIZATION:
                $partner = $this->parties->partnerForUser($user);

                return $partner && $col('organization') ? $query->where($col('organization'), $partner->getKey()) : $deny();
            case DataScope::BRANCH:
                $branches = $memberships->pluck('branch_id')->filter()->unique()->values()->all();

                return $branches !== [] && $col('branch') ? $query->whereIn($col('branch'), $branches) : $deny();
            case DataScope::TEAM:
                $teams = $memberships->pluck('team_code')->filter()->unique()->values()->all();
                if ($teams === [] || ! $col('assigned')) {
                    return $col('assigned') ? $query->where($col('assigned'), $user->getKey()) : $deny();
                }
                $userIds = TenantMembership::where('tenant_id', $tenantId)->where('status', 'ACTIVE')->whereIn('team_code', $teams)->pluck('user_id')->unique()->values()->all();

                return $query->whereIn($col('assigned'), $userIds);
            case DataScope::ASSIGNED:
                return $col('assigned') ? $query->where($col('assigned'), $user->getKey()) : $deny();
            case DataScope::OWN:
                $partyId = $this->parties->forUser($user)?->id;

                return $partyId && $col('own') ? $query->where($col('own'), $partyId) : $deny();
            default: // PLATFORM, REGULATOR_READ, no membership
                return $deny();
        }
    }
}
