<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use App\Domain\Tenancy\TenantContext;
use App\Models\{ApprovalRequest, Bordereau, CarrierBrokerAgreementRecord, Claim, CommissionAccrual, JournalRecord, Policy, PolicyIssuanceRequest, Quote, SettlementBatch, StickerBatch, StickerStock, TenantMembership, UnderwritingCase, User};
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
    /**
     * Read permissions the insurer roles hold under their carrier.* names (RoleCatalogue::CARRIER_*_PERMISSIONS),
     * accepted wherever the generic read permission is checked for a list or dashboard widget.
     *
     * Owner decision 2026-09-27 (docs/spec/RBAC_MATRIX_BROKER_CARRIER.md): the broker roles read their book in /broker
     * with broker.portal.read. The generic strings are NOT granted to them, because the core APIs behind those strings
     * (GET /api/v1/policies, /api/v1/claims ...) are tenant-wide; the portal rows are narrowed by PortalScope::narrowTable.
     * claims.view is the one claim-read permission (claims.read was retired, see RoleCatalogue::RENAMED_PERMISSIONS).
     */
    public const EQUIVALENT_READS = [
        'policies.read' => ['carrier.issuance.read', 'broker.portal.read'],
        'claims.view' => ['carrier.claims.read', 'broker.portal.read'],
        'quotes.read' => ['carrier.quote_requests.view', 'broker.portal.read'],
    ];

    /** True when the user holds the permission or one of its EQUIVALENT_READS. */
    public static function allowsRead(User $user, string $permission): bool
    {
        foreach ([$permission, ...(self::EQUIVALENT_READS[$permission] ?? [])] as $p) {
            if ((bool) rescue(fn () => $user->hasPermission($p), false, false)) {
                return true;
            }
        }

        return false;
    }

    public const READ_PERMISSIONS = [
        // Checked with allowsRead(), so the carrier.* equivalents count too (without them CARRIER_SUPER_ADMIN /
        // CARRIER_ADMIN / CARRIER_STAFF saw no Policies or Claims in /insurer).
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
     * A list of permissions means any one of them grants read (checked with allowsRead()).
     *
     * @var array<class-string, array<string, string|list<string>>> class => [panel => permission(s)]
     */
    public const PORTAL_SECTIONS = [
        Bordereau::class => ['insurer' => ['carrier.finance.read', 'bordereaux.view'], 'broker' => 'broker.finance.read'],
        SettlementBatch::class => ['insurer' => ['carrier.finance.read', 'settlement.read'], 'broker' => 'broker.finance.read'],
        CommissionAccrual::class => ['insurer' => ['finance.obligations.view', 'statements.read'], 'broker' => 'broker.finance.read'],
        // UI audit 2026-09-27: insurer-panel sections, same admin resources, gated by the permissions insurer roles hold.
        PolicyIssuanceRequest::class => ['insurer' => ['policies.issuance_queue.view', 'carrier.issuance.read']],
        UnderwritingCase::class => ['insurer' => ['underwriting.decide', 'carrier.referrals.read']],
        StickerBatch::class => ['insurer' => 'stickers.view'],
        StickerStock::class => ['insurer' => 'stickers.view'],
        JournalRecord::class => ['insurer' => ['ledger.read', 'ledger.post', 'ledger.approve']],
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
        $first = $arguments[0] ?? null;
        if ($panel === 'insurer' && (is_object($first) ? $first::class : $first) === Quote::class && ! in_array($ability, ['viewAny', 'view'], true)) {
            return false; // quotes are read-only in the insurer panel (no carrier write service behind the admin form)
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
        if ($tenantId === null || ! self::allowsRead($user, $permission)) {
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

        $p = self::PORTAL_SECTIONS[$class][$panel] ?? null;

        return ['permission' => $p === null ? null : (array) $p];
    }

    private function sectionDecision(User $user, string $ability, mixed $subject, array $section): bool
    {
        $tenantId = rescue(fn () => app(TenantContext::class)->id(), null, false);
        if (! in_array($ability, ['viewAny', 'view'], true) || $section['permission'] === null || $tenantId === null
            || ! collect($section['permission'])->contains(fn (string $p) => self::allowsRead($user, $p))) {
            return false;
        }
        if (! is_object($subject)) {
            return true;
        }
        if ($subject instanceof CarrierBrokerAgreementRecord) {
            return CarrierBrokerAgreementRecord::query()->visibleInPortal()->whereKey($subject->getKey())->exists();
        }

        // Sticker batches have no tenant_id (ownership is the stock's custodian_tenant_id); the resource query scopes them.
        $owner = $subject->getAttribute('tenant_id') ?? $subject->getAttribute('custodian_tenant_id');
        if ($owner !== null ? $owner !== $tenantId : ! ($subject instanceof StickerBatch)) {
            return false;
        }
        if (($subject instanceof PolicyIssuanceRequest || $subject instanceof UnderwritingCase) && ($carrier = PortalScope::carrierId()) !== null) {
            return $subject->getAttribute('carrier_id') === $carrier;
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
