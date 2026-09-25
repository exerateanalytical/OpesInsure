<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use App\Application\Identity\{CarrierScopeResolver, PartyResolver};
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

/**
 * Owner decision D4: extra row scoping for admin resources that the insurer /
 * broker portals reuse (no copies). Mirrors the boundaries the existing APIs
 * already apply — never wider than the admin tenant scope:
 *  - insurer portal: settlements / bordereaux narrowed to the caller's carrier
 *    (CarrierScopeResolver, as MobileCarrierFinanceService does);
 *  - broker portal: commission receivables narrowed to the caller's partner
 *    (PartyResolver::partnerForUser, as MobileBrokerOpsController::receivables);
 *  - both: carrier-broker agreements visible when the partner belongs to the
 *    portal tenant (CarrierBrokerAgreementController::index) or, for insurers,
 *    when the agreement is with the caller's carrier.
 * Outside a portal panel every method is a no-op.
 */
final class PortalScope
{
    public static function panel(): ?string
    {
        $id = rescue(fn () => Filament::getCurrentPanel()?->getId(), null, false);

        return $id !== null && PortalAccess::isPortal($id) ? $id : null;
    }

    public static function carrierId(): ?string
    {
        $user = auth()->user();
        $tenant = rescue(fn () => app(TenantContext::class)->id(), null, false);

        return self::panel() === 'insurer' && $user instanceof User && $tenant
            ? app(CarrierScopeResolver::class)->carrierIdFor($user, $tenant)
            : null;
    }

    public static function partnerId(): ?string
    {
        $user = auth()->user();

        return $user instanceof User ? app(PartyResolver::class)->partnerForUser($user)?->getKey() : null;
    }

    /** Settlements / bordereaux: tenant scope already applied by the resource; add the carrier narrowing. */
    public static function narrowToCarrier(Builder $q, string $column = 'carrier_id'): Builder
    {
        $carrier = self::carrierId();

        return $carrier ? $q->where($column, $carrier) : $q;
    }

    /** Commission receivables in the broker portal: the caller's own partner only (no partner = nothing). */
    public static function narrowToPartner(Builder $q, string $column = 'partner_id'): Builder
    {
        if (self::panel() !== 'broker') {
            return $q;
        }
        $partner = self::partnerId();

        return $partner ? $q->where($column, $partner) : $q->whereRaw('1 = 0');
    }
}
