<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use App\Models\TenantMembership;
use App\Models\User;

/**
 * REQ-UI-001: which membership roles may enter each web-experience Filament
 * panel and which tenant the panel is scoped to. The admin panel keeps
 * User::canAccessPanel(); the insurer and broker portals use this registry
 * (via AuthenticatePortal + PortalLogin) so CARRIER_* roles — refused by the
 * admin panel — get their own tenant-scoped workspace.
 *
 * Role codes come from App\Application\Identity\RoleCatalogue (no new roles).
 */
final class PortalAccess
{
    public const PORTAL_ROLES = [
        'insurer' => ['CARRIER_SUPER_ADMIN', 'CARRIER_ADMIN', 'CARRIER_STAFF', 'UNDERWRITER', 'SENIOR_UNDERWRITER', 'REINSURANCE_OFFICER', 'CUSTOMER_SERVICE', 'ADJUSTER',
            // UI audit 2026-09-27: insurer finance officers work in /insurer (ledger, cashier sessions, FX, receivables, settlements).
            'FINANCE_OFFICER',
            // Owner decision 2026-09-29 (D4 lifted): insurer claims handlers work their carrier's claims in /insurer.
            'CLAIMS_OFFICER', 'CLAIMS_MANAGER'],
        'broker' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER'],
    ];

    public static function isPortal(string $panelId): bool
    {
        return isset(self::PORTAL_ROLES[$panelId]);
    }

    /** @return list<string> */
    public static function roles(string $panelId): array
    {
        return self::PORTAL_ROLES[$panelId] ?? [];
    }

    /** The ACTIVE membership that grants entry (deterministic: oldest first), or null. */
    public function membershipFor(?User $user, string $panelId): ?TenantMembership
    {
        if ($user === null || $user->status !== 'ACTIVE' || ! self::isPortal($panelId)) {
            return null;
        }

        return TenantMembership::query()
            ->where('user_id', $user->getKey())
            ->where('status', 'ACTIVE')
            ->whereIn('role_code', self::roles($panelId))
            ->whereHas('tenant', fn ($q) => $q->where('status', 'ACTIVE'))
            ->orderBy('created_at')
            ->first();
    }

    public function allows(?User $user, string $panelId): bool
    {
        return $this->membershipFor($user, $panelId) !== null;
    }
}
