<?php

declare(strict_types=1);

namespace App\Application\Partners;

use App\Application\Identity\PartyResolver;
use App\Application\Identity\Rbac\DataScope;
use App\Application\Identity\Rbac\DataScopeResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision 2026-09-27 (docs/spec/RBAC_MATRIX_BROKER_CARRIER.md): which clients (parties) and which
 * colleagues a distribution user may see, by their effective data scope (DataScopeResolver) in the current tenant.
 * A broker company is its Partner (PartyResolver::partnerForUser); its book is the parties with an ACTIVE
 * customer_attribution to that partner, and a client is "assigned" to the user who recorded that attribution.
 *
 *  - ORGANIZATION (BROKER_ADMIN)     → the whole company book / every colleague of the company;
 *  - TEAM (BROKER_SUPERVISOR)        → clients recorded by the caller's team (DataScopeResolver::teamUserIds);
 *  - ASSIGNED (BROKER_STAFF, AGENT)  → clients the caller recorded;
 *  - BRANCH (BRANCH_MANAGER)         → clients recorded by users of the caller's branches;
 *  - OWN (CUSTOMER)                  → the caller's own party;
 *  - TENANT / CARRIER_RELATIONSHIP   → null: not book-scoped (tenant or carrier scoping applies instead);
 *  - anything else                   → nothing (fail closed).
 * An ORGANIZATION user without a partner link sees the whole tenant only when the tenant is a BROKER tenant
 * (tenantIsCompany); in a shared tenant they see nothing.
 *
 * Returned builders are sub-selects of one column (party_id / user_id) for whereIn(); callers keep their tenant filter.
 */
final class BookScope
{
    public function __construct(private readonly DataScopeResolver $scopes, private readonly PartyResolver $parties) {}

    public function scope(User $user): ?DataScope
    {
        return rescue(fn () => $this->scopes->effectiveScope($user), null, false);
    }

    /** Party ids the caller may see, or null when the caller is not book-scoped. */
    public function parties(User $user): ?Builder
    {
        $attributions = fn (): Builder => DB::table('customer_attributions')->where('status', 'ACTIVE')->select('party_id');
        $partner = fn (): ?string => $this->parties->partnerForUser($user)?->getKey();

        return match ($this->scope($user)) {
            DataScope::TENANT, DataScope::CARRIER_RELATIONSHIP => null,
            DataScope::ORGANIZATION => ($p = $partner()) ? $attributions()->where('partner_id', $p) : (self::tenantIsCompany() ? null : self::none('party_id')),
            DataScope::TEAM => ($p = $partner()) ? $attributions()->where('partner_id', $p)->whereIn('recorded_by', $this->scopes->teamUserIds($user)) : self::none('party_id'),
            DataScope::ASSIGNED => ($p = $partner()) ? $attributions()->where('partner_id', $p)->where('recorded_by', $user->getKey()) : self::none('party_id'),
            DataScope::BRANCH => $attributions()->whereIn('recorded_by', $this->branchUsers($user)),
            DataScope::OWN => $user->party_id ? DB::query()->selectRaw('?::uuid as party_id', [$user->party_id]) : self::none('party_id'),
            default => self::none('party_id'),
        };
    }

    /** User ids of the colleagues the caller may see (staff list), or null when not book-scoped. */
    public function users(User $user): ?Builder
    {
        $company = fn (): ?string => $this->parties->partnerForUser($user)?->party_id;

        return match ($this->scope($user)) {
            DataScope::TENANT, DataScope::CARRIER_RELATIONSHIP => null,
            DataScope::ORGANIZATION => ($party = $company()) ? DB::table('users')->where('party_id', $party)->select('id as user_id') : (self::tenantIsCompany() ? null : self::none('user_id')),
            DataScope::TEAM => DB::table('users')->whereIn('id', $this->scopes->teamUserIds($user))->select('id as user_id'),
            DataScope::BRANCH => $this->branchUsers($user),
            default => DB::query()->selectRaw('?::uuid as user_id', [$user->getKey()]),
        };
    }

    /**
     * A BROKER-type tenant is the broker company itself, so an ORGANIZATION-scope user with no partner link reads the
     * (already tenant-filtered) rows unnarrowed there; in a shared tenant (PLATFORM, INSURER ...) they read nothing.
     */
    public static function tenantIsCompany(): bool
    {
        $tenant = app(TenantContext::class)->id();

        return $tenant !== null && DB::table('tenants')->where('id', $tenant)->value('type') === 'BROKER';
    }

    private function branchUsers(User $user): Builder
    {
        return DB::table('tenant_memberships')->where('tenant_id', app(TenantContext::class)->id())->where('status', 'ACTIVE')->whereIn('branch_id', $this->scopes->branchIds($user) ?: ['00000000-0000-0000-0000-000000000000'])
            ->select('user_id');
    }

    private static function none(string $column): Builder
    {
        return DB::query()->selectRaw("null::uuid as {$column}")->whereRaw('1 = 0');
    }
}
