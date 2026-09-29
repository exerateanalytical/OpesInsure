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

    /**
     * Keep only the ids of $table the caller may see in the current portal (no-op outside a portal or at tenant-wide
     * scope). For screens fed by service lists (health queues) rather than an Eloquent query.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    public static function visibleOf(string $table, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $sub = self::visibleIds($table);
        if ($sub === null || $ids === []) {
            return array_values($ids);
        }
        $keep = DB::table($table)->whereIn('id', $ids)->whereIn('id', $sub)->pluck('id')->map(fn ($v) => (string) $v)->all();

        return array_values(array_filter($ids, fn ($id) => in_array((string) $id, $keep, true)));
    }

    /**
     * R4: isOwnRecord() for a book table, decided for the whole page at once. A list asks it per row and per action;
     * the first ask checks every row of that table retrieved in this request (RequestMemo::retrievedIds) with ONE
     * visibleOf() query and memoises each answer for the rest of the request (same rule, same result per id).
     */
    private static function ownVisible(string $panel, string $tenant, string $table, string $id): bool
    {
        return \App\Application\Identity\Rbac\RequestMemo::rowFlag('own:'.$panel.':'.$tenant.':'.auth()->id(), $table, $id, fn (array $ids) => self::visibleOf($table, $ids));
    }

    /** Carrier forced by visibleOfCarrier() (API callers, outside any panel). */
    private static ?string $forcedCarrier = null;

    /**
     * visibleOf() for an explicit carrier, outside the insurer panel (the health API guard, EnsureHealthCarrierScope).
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    public static function visibleOfCarrier(string $table, array $ids, string $carrierId): array
    {
        $previous = self::$forcedCarrier;
        self::$forcedCarrier = $carrierId;
        try {
            return self::visibleOf($table, $ids);
        } finally {
            self::$forcedCarrier = $previous;
        }
    }

    /** Sub-select of the visible ids of $table, or null when nothing narrows it (not in a portal, tenant-wide scope). */
    private static function visibleIds(string $table): ?QueryBuilder
    {
        $panel = self::$forcedCarrier !== null ? 'insurer' : self::panel();
        $user = auth()->user();
        if ($panel === null || ! $user instanceof User) {
            return null;
        }
        $carrier = $panel === 'insurer' ? (self::$forcedCarrier ?? self::carrierId()) : null;
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
            // Health (insurer panel): a pre-authorization belongs to its carrier_id (falling back to the policy's carrier when
            // unset); a provider claim to its policy or verified pre-authorization; a settlement batch and a dispute follow
            // the provider claims they carry.
            'health_preauthorizations' => $carrier
                ? DB::table('health_preauthorizations')->where(fn ($w) => $w->where('carrier_id', $carrier)
                    ->orWhere(fn ($x) => $x->whereNull('carrier_id')->whereIn('policy_id', self::visibleIds('policies'))))->select('id')
                : $in('health_preauthorizations', 'policy_id', self::visibleIds('policies')),
            'health_provider_claims' => DB::table('health_provider_claims')->where(fn ($w) => $w->whereIn('policy_id', self::visibleIds('policies'))
                ->orWhereIn('preauth_id', self::visibleIds('health_preauthorizations')))->select('id'),
            'health_provider_settlement_batches' => DB::table('health_provider_claims')->whereNotNull('settlement_batch_id')
                ->whereIn('id', self::visibleIds('health_provider_claims'))->select('settlement_batch_id as id'),
            'provider_disputes' => DB::table('provider_disputes')->where(fn ($w) => $w->whereIn('health_provider_claim_id', self::visibleIds('health_provider_claims'))
                ->orWhereIn('settlement_batch_id', self::visibleIds('health_provider_settlement_batches')))->select('id'),
            default => $none,
        };
    }

    /** Staff list (tenant memberships) in the broker portal: the colleagues of the caller's data scope (BookScope::users). */
    public static function narrowStaff(EloquentBuilder $q): EloquentBuilder
    {
        $user = auth()->user();
        if (self::panel() === 'insurer') {
            // R7 (2026-09-29): an insurer sees its own carrier's staff only, never another carrier's in the same tenant.
            return $q->where('carrier_id', self::carrierId() ?? '');
        }
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

    /** Tables whose portal visibility visibleIds() knows (anything else is decided by the column rules in isOwnRecord). */
    public const OWNED_TABLES = ['policies', 'quotes', 'proposals', 'claims', 'claim_payments', 'payment_intents', 'underwriting_cases',
        'health_preauthorizations', 'health_provider_claims', 'health_provider_settlement_batches', 'provider_disputes'];

    /** Partner-owned finance rows: in the broker portal a book-scoped caller only acts on their own partner's rows. */
    private const PARTNER_OWNED = [\App\Models\CommissionAccrual::class, \App\Models\PartnerStatement::class, \App\Models\PartnerPayoutRequest::class];

    /**
     * Owner decision 2026-09-29 (portals writable, D4 lifted — docs/spec/PORTAL_WRITE_RULES.md): true when $record
     * belongs to the caller's own organisation in the current portal. Always true outside a portal (the admin panel keeps
     * its own tenant scope). Rules, all of which must hold:
     *  - tenant: a record carrying tenant_id (or custodian_tenant_id) must be in the portal tenant;
     *  - insurer: a record carrying carrier_id must be the caller's carrier (CarrierScopeResolver);
     *  - broker: commission accruals / statements / payouts must be the caller's partner when the caller is book-scoped;
     *  - carrier-broker agreements: insurer = own carrier_id, broker = CarrierBrokerAgreementRecord::visibleInPortal;
     *  - book tables (policies, claims, proposals, health ...): the same visibleIds() the lists use.
     * Fails closed: no portal tenant, no user, or an unreadable record = false.
     */
    public static function isOwnRecord(mixed $record): bool
    {
        $panel = self::panel();
        if ($panel === null) {
            return true;
        }
        if (! $record instanceof \Illuminate\Database\Eloquent\Model || ! auth()->user() instanceof User) {
            return false;
        }
        $tenant = rescue(fn () => app(TenantContext::class)->id(), null, false);
        if ($tenant === null) {
            return false;
        }
        $owner = $record->getAttribute('tenant_id') ?? $record->getAttribute('custodian_tenant_id');
        $carrier = $panel === 'insurer' ? self::carrierId() : null;

        if ($record instanceof \App\Models\CarrierBrokerAgreementRecord) {
            return $panel === 'insurer'
                ? $carrier !== null && (string) $record->getAttribute('carrier_id') === $carrier
                : \App\Models\CarrierBrokerAgreementRecord::query()->visibleInPortal()->whereKey($record->getKey())->exists();
        }
        if ($owner !== null && (string) $owner !== (string) $tenant) {
            return false;
        }
        if ($carrier !== null && array_key_exists('carrier_id', $record->getAttributes()) && (string) $record->getAttribute('carrier_id') !== $carrier) {
            return false;
        }
        if ($panel === 'broker' && in_array($record::class, self::PARTNER_OWNED, true)) {
            // Same narrowing as the lists: accruals = narrowToPartner, statements / payouts = narrowToBookPartner.
            $partner = $record instanceof \App\Models\CommissionAccrual ? (self::partnerId() ?? '') : self::brokerPartnerId();
            if ($partner !== null && ($partner === '' || (string) $record->getAttribute('partner_id') !== (string) $partner)) {
                return false;
            }
        }
        $table = $record->getTable();
        if (in_array($table, self::OWNED_TABLES, true)) {
            return self::ownVisible($panel, (string) $tenant, $table, (string) $record->getKey());
        }

        if ($owner !== null || $record instanceof \App\Models\StickerBatch) {
            return true;
        }

        // Carrier-owned global rows (no tenant_id), insurer panel only: the caller's own carrier.
        if ($carrier === null) {
            return false;
        }
        if ($record instanceof \App\Models\Carrier) {
            return (string) $record->getKey() === $carrier;
        }
        if ($record instanceof \App\Models\TariffVersion) {
            return \App\Models\InsuranceProduct::query()->whereKey($record->getAttribute('insurance_product_id'))->where('carrier_id', $carrier)->exists();
        }

        // InsuranceProduct, CarrierSetup, authority limits ...: carrier_id already matched above.
        return array_key_exists('carrier_id', $record->getAttributes()) && $record->getAttribute('carrier_id') !== null;
    }

    /**
     * The single write check for portal screens: the caller holds $permission (the SAME string the API route's
     * RequirePermission uses) AND, when a record is given, isOwnRecord($record). Outside a portal it is the plain
     * permission check. Use it in ->authorize()/->visible() of custom actions; WorkflowAction already applies it.
     */
    public static function allowsWrite(?string $permission, mixed $record = null): bool
    {
        $user = auth()->user();
        if (! $user instanceof User || ($permission !== null && ! (bool) rescue(fn () => $user->hasPermission($permission), false, false))) {
            return false;
        }

        return $record === null || self::isOwnRecord($record);
    }

    /** Partner statements / payout requests in the broker portal: a book-scoped caller sees their own partner's rows only. */
    public static function narrowToBookPartner(Builder $q, string $column = 'partner_id'): Builder
    {
        $partner = self::brokerPartnerId();

        return match ($partner) {
            null => $q,
            '' => $q->whereRaw('1 = 0'),
            default => $q->where($column, $partner),
        };
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
