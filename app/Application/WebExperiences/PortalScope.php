<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use App\Application\Identity\{CarrierScopeResolver, PartyResolver};
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Filament\Facades\Filament;
use App\Application\Partners\BookScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

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

    /**
     * Owner decision 2026-09-27 (docs/spec/RBAC_MATRIX_BROKER_CARRIER.md): the book rows of $table the caller may
     * see inside a portal, on top of the tenant filter the caller already applied. Broker panel: the parties of the
     * caller's data scope (BookScope: company / team / assigned / branch). Insurer panel: the caller's carrier
     * (CarrierScopeResolver). Used by the Quote / Policy / Claim resources and by every KPI record set (dashboard
     * tiles and list widgets), so a list, a tile and its drill-down always agree. Outside a portal: no-op.
     *
     * @template T of EloquentBuilder|QueryBuilder
     *
     * @param  T  $q
     * @return T
     */
    public static function narrowTable(EloquentBuilder|QueryBuilder $q, string $table): EloquentBuilder|QueryBuilder
    {
        $ids = self::visibleIds($table);

        return $ids === null ? $q : $q->whereIn($table.'.id', $ids);
    }

    /** Sub-select of the visible ids of $table, or null when nothing narrows it (not in a portal, tenant-wide scope). */
    private static function visibleIds(string $table): ?QueryBuilder
    {
        $panel = self::panel();
        $user = auth()->user();
        if ($panel === null || ! $user instanceof User) {
            return null;
        }
        $carrier = $panel === 'insurer' ? self::carrierId() : null;
        $parties = $panel === 'broker' ? app(BookScope::class)->parties($user) : null;
        if ($carrier === null && $parties === null) {
            return null;
        }
        $in = fn (string $t, string $col, ?QueryBuilder $sub) => $sub === null ? DB::table($t)->select($t.'.id') : DB::table($t)->whereIn($col, $sub)->select($t.'.id');
        $none = DB::table($table)->whereRaw('1 = 0')->select($table.'.id');

        return match ($table) {
            'policies' => $carrier ? DB::table('policies')->where('carrier_id', $carrier)->select('id') : $in('policies', 'party_id', $parties),
            'quotes' => $carrier ? DB::table('quote_offers')->where('carrier_id', $carrier)->select('quote_id as id') : $in('quotes', 'party_id', $parties),
            'proposals' => $carrier ? DB::table('proposals')->join('quote_offers', 'quote_offers.id', '=', 'proposals.quote_offer_id')->where('quote_offers.carrier_id', $carrier)->select('proposals.id')
                : $in('proposals', 'party_id', $parties),
            'claims' => $in('claims', 'policy_id', self::visibleIds('policies')),
            'claim_payments' => $in('claim_payments', 'claim_id', self::visibleIds('claims')),
            'payment_intents' => $in('payment_intents', 'proposal_id', self::visibleIds('proposals')),
            'underwriting_cases' => $carrier ? DB::table('underwriting_cases')->where('carrier_id', $carrier)->select('id') : $in('underwriting_cases', 'proposal_id', self::visibleIds('proposals')),
            default => $none,
        };
    }

    /** Staff list (tenant memberships) in the broker portal: the colleagues of the caller's data scope (BookScope::users). */
    public static function narrowStaff(EloquentBuilder $q): EloquentBuilder
    {
        $user = auth()->user();
        $users = self::panel() === 'broker' && $user instanceof User ? app(BookScope::class)->users($user) : null;

        return $users === null ? $q : $q->whereIn('user_id', $users);
    }

    /**
     * Carrier–broker agreements in the broker portal: a book-scoped caller (PartnerBook::isBookScoped) sees only
     * their own company's agreements; null = no partner narrowing, '' = nothing.
     */
    public static function brokerPartnerId(): ?string
    {
        $user = auth()->user();
        if (self::panel() !== 'broker' || ! $user instanceof User || ! app(\App\Application\Partners\PartnerBook::class)->isBookScoped($user)) {
            return null;
        }

        $partner = self::partnerId();

        return $partner === null && BookScope::tenantIsCompany() ? null : (string) $partner;
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
