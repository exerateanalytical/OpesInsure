<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use App\Domain\Tenancy\TenantContext;
use App\Models\{Claim, Policy, Quote, User};
use Filament\Facades\Filament;

/**
 * RBAC for the records the insurer / broker portals reuse from the admin
 * resources. The admin model policies (PolicyPolicy, ClaimPolicy …) are
 * role lists for back-office staff; inside a portal the read abilities are
 * decided by the governed permission strings instead (REQ-RBAC-001), always
 * inside the portal tenant. Writes are NOT widened: they fall through to the
 * existing model policies. Outside a portal panel this does nothing.
 */
final class PortalAuthorization
{
    public const READ_PERMISSIONS = [
        Policy::class => 'policies.read',
        Claim::class => 'claims.view',
        Quote::class => 'quotes.read',
    ];

    /** Gate::before hook: null = no opinion. */
    public function before(?User $user, string $ability, array $arguments): ?bool
    {
        if ($user === null || ! in_array($ability, ['viewAny', 'view'], true)) {
            return null;
        }
        $panel = rescue(fn () => Filament::getCurrentPanel()?->getId(), null, false);
        if ($panel === null || ! PortalAccess::isPortal($panel)) {
            return null;
        }

        $subject = $arguments[0] ?? null;
        $class = is_object($subject) ? $subject::class : (is_string($subject) ? $subject : null);
        $permission = $class ? (self::READ_PERMISSIONS[$class] ?? null) : null;
        if ($permission === null) {
            return null;
        }

        $tenantId = app(TenantContext::class)->id();
        if ($tenantId === null || ! $user->hasPermission($permission)) {
            return false;
        }

        return ! is_object($subject) || $subject->getAttribute('tenant_id') === $tenantId;
    }
}
