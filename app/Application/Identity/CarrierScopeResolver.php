<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Identity\Rbac\DataScope;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Decides which insurer's data a caller of /mobile/carrier/* may see.
 *
 *  - A membership linked to a carrier (tenant_memberships.carrier_id), or a
 *    CARRIER-type Partner carrying compliance.carrier_id (the older demo
 *    convention), scopes every read to that one carrier.
 *  - An insurer role (CARRIER_ADMIN / CARRIER_STAFF) that is NOT linked is
 *    only allowed tenant-wide inside a CARRIER-type tenant (the tenant IS
 *    the insurer); anywhere else it is refused rather than shown every
 *    insurer's book.
 *  - Any other role that passed the carrier.* permission gate (platform
 *    staff) keeps the tenant-wide view.
 */
final class CarrierScopeResolver
{
    public const CARRIER_ROLES = RoleCatalogue::CARRIER_ROLES;

    public function __construct(private readonly PartyResolver $parties)
    {
    }

    /** @return string|null carrier id, or null for tenant-wide */
    public function carrierIdFor(User $user, string $tenantId): ?string
    {
        $memberships = TenantMembership::where('tenant_id', $tenantId)->where('user_id', $user->id)->where('status', 'ACTIVE')->get();

        $linked = $memberships->firstWhere(fn ($m) => $m->carrier_id !== null);
        if ($linked) {
            return (string) $linked->carrier_id;
        }

        $partner = $this->parties->partnerForUser($user);
        if ($partner instanceof Partner && $partner->type === 'CARRIER' && ! empty($partner->compliance['carrier_id'])) {
            return (string) $partner->compliance['carrier_id'];
        }

        // Insurer-side roles (every role whose default scope is the carrier
        // relationship: carrier admins/staff, underwriters, customer service,
        // reinsurance) must be linked; only TENANT-scope staff in THIS tenant
        // keep the tenant-wide view (REQ-TEN-003 — a SYSTEM_ADMIN membership in
        // another tenant no longer counts).
        $isCarrierRole = $memberships->contains(fn ($m) => RoleCatalogue::defaultScope((string) $m->role_code) === DataScope::CARRIER_RELATIONSHIP);
        $isPrivileged = $memberships->contains(fn ($m) => RoleCatalogue::defaultScope((string) $m->role_code) === DataScope::TENANT
            && ! in_array($m->role_code, ['CUSTOMER', 'AGENT', 'BROKER_STAFF', 'BROKER_ADMIN'], true));

        if ($isCarrierRole && ! $isPrivileged && Tenant::whereKey($tenantId)->value('type') !== 'CARRIER') {
            throw new AuthorizationException('Your insurer account is not linked to a carrier yet.');
        }

        return null;
    }
}
