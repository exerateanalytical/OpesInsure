<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use App\Domain\Tenancy\TenantContext;
use App\Models\{Bordereau, CarrierBrokerAgreementRecord, Claim, CommissionAccrual, Policy, Quote, SettlementBatch, TenantMembership, User};
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

    /**
     * Owner decision D4: broker ERP / carrier portal sections, READ-ONLY in the
     * portals (every non-view ability is refused there; writes stay in the
     * admin panel and the APIs, unchanged). Permission strings are the ones the
     * existing APIs already require for the same data.
     *
     * @var array<class-string, array<string, string>> class => [panel => permission]
     */
    public const PORTAL_SECTIONS = [
        Bordereau::class => ['insurer' => 'carrier.finance.read', 'broker' => 'broker.finance.read'],
        SettlementBatch::class => ['insurer' => 'carrier.finance.read', 'broker' => 'broker.finance.read'],
        CommissionAccrual::class => ['broker' => 'broker.finance.read'],
        CarrierBrokerAgreementRecord::class => ['insurer' => 'distribution.agreements.view', 'broker' => 'distribution.agreements.view'],
        TenantMembership::class => ['broker' => 'broker.portal.read'],
    ];

    /** Gate::before hook: null = no opinion. */
    public function before(?User $user, string $ability, array $arguments): ?bool
    {
        if ($user === null) {
            return null;
        }
        $panel = rescue(fn () => Filament::getCurrentPanel()?->getId(), null, false);
        if ($panel === null || ! PortalAccess::isPortal($panel)) {
            return null;
        }

        $section = $this->section($arguments[0] ?? null, $panel);
        if ($section !== null) {
            return $this->sectionDecision($user, $ability, $arguments[0] ?? null, $section);
        }
        if (! in_array($ability, ['viewAny', 'view'], true)) {
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

    /** @return array{permission: ?string}|null null when the subject is not a D4 portal section. */
    private function section(mixed $subject, string $panel): ?array
    {
        $class = is_object($subject) ? $subject::class : (is_string($subject) ? $subject : null);
        if ($class === null || ! isset(self::PORTAL_SECTIONS[$class])) {
            return null;
        }

        return ['permission' => self::PORTAL_SECTIONS[$class][$panel] ?? null];
    }

    private function sectionDecision(User $user, string $ability, mixed $subject, array $section): bool
    {
        $tenantId = rescue(fn () => app(TenantContext::class)->id(), null, false);
        if (! in_array($ability, ['viewAny', 'view'], true) || $section['permission'] === null || $tenantId === null || ! $user->hasPermission($section['permission'])) {
            return false;
        }
        if (! is_object($subject)) {
            return true;
        }
        if ($subject instanceof CarrierBrokerAgreementRecord) {
            return CarrierBrokerAgreementRecord::query()->visibleInPortal()->whereKey($subject->getKey())->exists();
        }

        if ($subject->getAttribute('tenant_id') !== $tenantId) {
            return false;
        }
        if (($subject instanceof Bordereau || $subject instanceof SettlementBatch) && ($carrier = PortalScope::carrierId()) !== null) {
            return $subject->getAttribute('carrier_id') === $carrier;
        }
        if ($subject instanceof CommissionAccrual && PortalScope::panel() === 'broker') {
            return ($partner = PortalScope::partnerId()) !== null && $subject->getAttribute('partner_id') === $partner;
        }

        return true;
    }
}
