<?php

namespace App\Policies;

use App\Application\WebExperiences\PortalAuthorization;
use App\Application\WebExperiences\PortalScope;
use App\Models\{TariffVersion, User};

/**
 * Admin panel: the back-office role list (unchanged). Insurer portal (owner rule 2026-09-29): permission-based,
 * the API's tariff.* strings (POST tariffs = tariff.manage), own carrier's products only. Creation goes through
 * TariffGovernanceService (CreateTariffVersion); the generic edit form stays refused in the portal.
 */
final class TariffVersionPolicy
{
    public const PORTAL_READS = ['tariff.manage', 'tariff.approve', 'tariff.publish', 'catalogue.view'];

    private function role(User $u, array $r): bool
    {
        return $u->memberships()->where('status', 'ACTIVE')->whereIn('role_code', $r)->exists();
    }

    public function viewAny(User $u): bool
    {
        if (PortalScope::panel() !== null) {
            return PortalScope::panel() === 'insurer' && collect(self::PORTAL_READS)->contains(fn (string $p) => PortalAuthorization::allowsRead($u, $p));
        }

        return $this->role($u, ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'PRODUCT_ADMIN', 'PRICING_ACTUARY', 'PRICING_APPROVER']);
    }

    public function view(User $u, TariffVersion $m): bool
    {
        if (PortalScope::panel() !== null) {
            return $this->viewAny($u) && PortalScope::isOwnRecord($m);
        }

        return $this->viewAny($u);
    }

    public function create(User $u): bool
    {
        if (PortalScope::panel() !== null) {
            return PortalScope::panel() === 'insurer' && PortalScope::carrierId() !== null && PortalScope::allowsWrite('tariff.manage');
        }

        return $this->role($u, ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'PRICING_ACTUARY']);
    }

    public function update(User $u, TariffVersion $m): bool
    {
        return PortalScope::panel() === null && $this->create($u) && $m->status === 'DRAFT';
    }

    public function delete(User $u, TariffVersion $m): bool
    {
        return false;
    }
}
