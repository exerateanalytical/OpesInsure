<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Application\WebExperiences\PortalAccess;
use Filament\Facades\Filament;

/**
 * Portal panels (insurer, broker): fixed to the tenant of the membership that granted entry to this panel
 * (PortalAccess::membershipFor, as ResolvePortalTenant). Organisation and branch settings are editable by that
 * tenant's administrators only; every portal user can set their own language and display timezone.
 */
final class PortalOrganisationSettings extends OrganizationSettings
{
    public const MANAGER_ROLES = ['CARRIER_ADMIN', 'CARRIER_SUPER_ADMIN', 'BROKER_ADMIN'];

    public static function canAccess(): bool
    {
        return app(PortalAccess::class)->membershipFor(auth()->user(), Filament::getCurrentOrDefaultPanel()->getId()) !== null;
    }

    protected function resolveTenantId(): ?string
    {
        return app(PortalAccess::class)->membershipFor(auth()->user(), Filament::getCurrentOrDefaultPanel()->getId())?->tenant_id;
    }

    protected function canManageTenant(): bool
    {
        return $this->tenantId !== null && (bool) auth()->user()?->memberships()->where('status', 'ACTIVE')
            ->where('tenant_id', $this->tenantId)->whereIn('role_code', self::MANAGER_ROLES)->exists();
    }
}
