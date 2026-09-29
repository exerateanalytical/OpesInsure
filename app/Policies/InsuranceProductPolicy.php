<?php

namespace App\Policies;

use App\Application\WebExperiences\PortalAuthorization;
use App\Application\WebExperiences\PortalScope;
use App\Models\{InsuranceProduct, User};

/**
 * Admin panel: the back-office role list (unchanged). Insurer portal (owner rule 2026-09-29): permission-based,
 * the API's catalogue.* strings, own carrier only; generic create / edit forms stay refused there (new products go
 * through CatalogueActions::createProduct -> CatalogueService, like POST catalogue/products).
 */
final class InsuranceProductPolicy
{
    /** Any catalogue permission lets the holder see the versions it acts on (GET governance routes: catalogue.view). */
    public const PORTAL_READS = ['catalogue.view', 'catalogue.manage', 'catalogue.review', 'catalogue.test', 'catalogue.publish'];

    private function role(User $u, array $r): bool
    {
        return $u->memberships()->where('status', 'ACTIVE')->whereIn('role_code', $r)->exists();
    }

    private function portalRead(User $u): bool
    {
        return collect(self::PORTAL_READS)->contains(fn (string $p) => PortalAuthorization::allowsRead($u, $p));
    }

    public function viewAny(User $u): bool
    {
        if (PortalScope::panel() !== null) {
            return PortalScope::panel() === 'insurer' && $this->portalRead($u);
        }

        return $this->role($u, ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'PRODUCT_ADMIN', 'PRODUCT_VIEWER', 'BROKER_ADMIN']);
    }

    public function view(User $u, InsuranceProduct $m): bool
    {
        if (PortalScope::panel() !== null) {
            return $this->viewAny($u) && PortalScope::isOwnRecord($m);
        }

        return $this->viewAny($u);
    }

    public function create(User $u): bool
    {
        return PortalScope::panel() === null && $this->role($u, ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'PRODUCT_ADMIN']);
    }

    public function update(User $u, InsuranceProduct $m): bool
    {
        return $this->create($u) && $m->status === 'DRAFT';
    }

    public function delete(User $u, InsuranceProduct $m): bool
    {
        return false;
    }
}
