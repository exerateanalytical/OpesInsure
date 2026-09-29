<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use App\Application\Policies\Http\PolicyCancellationController;
use App\Application\Reporting\Kpi\KpiCatalogueService;
use App\Application\Reporting\Kpi\KpiEvaluator;
use App\Domain\Tenancy\TenantContext;
use App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace\PartnerCarrierWorkspaceController;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Figures behind the /insurer dashboards (CAR-002 … CAR-007). No figure of its own making:
 *  - counts / sums that exist as governed KPIs are evaluated through KpiEvaluator (policies.active, policies.issued,
 *    premium.written, claims.open, claims.outstanding_reserve, claims.reported, underwriting.queue …), which already
 *    narrows every record set to the caller's carrier inside the insurer panel (PortalScope::narrowTable);
 *  - breakdowns (by status, line, product) run on the SAME narrowed base (policies / claims of the tenant, then
 *    PortalScope::narrowTable), so a breakdown always adds up to its KPI tile;
 *  - intermediaries come from the carrier workspace API (GET mobile/partner/carrier/partners, carrier.dashboard.read).
 * Money is kept per currency (minor units); ratios are computed in the tenant currency only.
 */
final class InsurerDashboards
{
    public const PERIODS = ['30d', '90d', 'ytd', '12m'];

    public const DEFAULT_PERIOD = 'ytd';

    /** Claim states counted as "closed" for the performance figures. */
    public const CLOSED_CLAIMS = ['CLOSED', 'CLOSED_PAID', 'PAID', 'SETTLED', 'REJECTED', 'WITHDRAWN'];

    public function __construct(private readonly KpiCatalogueService $catalogue, private readonly KpiEvaluator $evaluator) {}

    /** @return array{from: CarbonImmutable, to: CarbonImmutable} */
    public static function period(?string $key): array
    {
        $now = CarbonImmutable::now();

        return ['to' => $now->endOfDay(), 'from' => match (in_array($key, self::PERIODS, true) ? $key : self::DEFAULT_PERIOD) {
            '30d' => $now->subDays(30)->startOfDay(),
            '90d' => $now->subDays(90)->startOfDay(),
            '12m' => $now->subMonths(12)->startOfMonth(),
            default => $now->startOfYear(),
        }];
    }

    public function tenant(): string
    {
        return (string) app(TenantContext::class)->id();
    }

    public function currency(): string
    {
        $t = $this->tenant();

        return \App\Application\Identity\Rbac\RequestMemo::remember('tenant-currency:'.$t, fn () => (string) (Tenant::query()->whereKey($t)->value('currency') ?: 'XAF'));
    }

    /** The caller's policies (tenant, then carrier inside /insurer). */
    public function policies(): Builder
    {
        return PortalScope::narrowTable(DB::table('policies')->where('policies.tenant_id', $this->tenant()), 'policies');
    }

    /** The caller's claims (tenant, then the claims of the carrier's policies inside /insurer). */
    public function claims(): Builder
    {
        return PortalScope::narrowTable(DB::table('claims')->where('claims.tenant_id', $this->tenant()), 'claims');
    }

    /** @return array{value:int, by_currency:array<string,int>|null} */
    public function kpi(string $code, array $period = []): array
    {
        $t = $this->tenant();

        return $this->evaluator->value($t, $this->catalogue->resolve($t, $code), array_map(
            fn ($v) => $v instanceof CarbonImmutable ? $v->toDateString() : $v, $period));
    }

    /** @return array<string, array{value:int, by_currency:array<string,int>|null}> 'YYYY-MM' => the KPI for that month, last $months months */
    public function kpiMonthly(string $code, int $months): array
    {
        $t = $this->tenant();
        $from = CarbonImmutable::now()->startOfMonth()->subMonths($months - 1);

        return $this->evaluator->monthly($t, $this->catalogue->resolve($t, $code), ['from' => $from->toDateString(), 'to' => CarbonImmutable::now()->endOfMonth()->toDateString()]);
    }

    /** @param array<string,int>|null $byCurrency */
    public static function money(?array $byCurrency): string
    {
        if (! $byCurrency) {
            return Money::format(0, 'XAF');
        }

        return implode(' · ', array_map(fn ($ccy, $minor) => Money::format((int) $minor, (string) $ccy), array_keys($byCurrency), $byCurrency));
    }

    public static function ratio(int $num, int $den): ?float
    {
        return $den > 0 ? round($num * 100 / $den, 1) : null;
    }

    public static function percent(?float $v): string
    {
        return $v === null ? '—' : number_format($v, 1, ',', ' ').' %';
    }

    // ------------------------------------------------------------------ CAR-002 operations (the /insurer home)

    /** @return list<array{key:string, value:string, tone:string, url:?string}> */
    public function operations(): array
    {
        $cancellations = $this->cancellationQueue()->count();

        return [
            $this->tile('active_policies', (string) $this->kpi('policies.active')['value'], 'success', 'policies'),
            $this->tile('pending_issuance', (string) ($v = $this->kpi('policies.pending_issuance')['value']), $v > 0 ? 'warning' : 'success', 'policy-issuances'),
            $this->tile('underwriting_queue', (string) ($v = $this->kpi('underwriting.queue')['value']), $v > 0 ? 'warning' : 'success', 'underwriting-cases'),
            $this->tile('open_claims', (string) $this->kpi('claims.open')['value'], 'info', 'claims'),
            $this->tile('outstanding_reserve', self::money($this->kpi('claims.outstanding_reserve')['by_currency']), 'info', 'claims'),
            $this->tile('cancellations_pending', (string) $cancellations, $cancellations > 0 ? 'warning' : 'success', 'cancellation-review'),
            $this->tile('expiring_30d', (string) ($v = $this->kpi('policies.expiring_30d')['value']), $v > 0 ? 'warning' : 'success', 'policies'),
            $this->tile('failed_claim_payments', (string) ($v = $this->kpi('claims.failed_payments')['value']), $v > 0 ? 'danger' : 'success', 'claims'),
        ];
    }

    /** Cancellation requests awaiting review / decision on the caller's policies (same states as the API queue). */
    public function cancellationQueue(): \Illuminate\Database\Eloquent\Builder
    {
        return \App\Application\Policies\Cancellation\PolicyCancellation::query()
            ->where('tenant_id', $this->tenant())
            ->whereIn('status', PolicyCancellationController::QUEUE_STATES)
            ->whereIn('policy_id', $this->policies()->select('policies.id'));
    }

    // ------------------------------------------------------------------ CAR-003 production

    /** @return list<array{key:string, value:string, tone:string, url:?string}> */
    public function production(?string $periodKey): array
    {
        $p = self::period($periodKey);
        $issued = $this->kpi('policies.issued', $p)['value'];
        $written = $this->kpi('premium.written', $p)['by_currency'] ?? [];
        $ccy = $this->currency();
        $avg = $issued > 0 ? Money::format(intdiv((int) ($written[$ccy] ?? 0), max(1, (int) $this->policies()->whereNotNull('issued_at')->whereBetween('issued_at', [$p['from'], $p['to']])->where('currency', $ccy)->count())), $ccy) : '—';
        $quotes = $this->kpi('quotes.created', $p)['value'];

        return [
            $this->tile('policies_issued', (string) $issued, 'success', 'policies'),
            $this->tile('written_premium', self::money($written), 'success', null),
            $this->tile('average_premium', $avg, 'info', null),
            $this->tile('quotes_received', (string) $quotes, 'info', 'quotes'),
            $this->tile('conversion_rate', self::percent(self::ratio($issued, $quotes)), 'info', null),
        ];
    }

    /** Monthly issued policies and written premium (tenant currency) over the last $months months. */
    public function productionSeries(int $months = 12): array
    {
        $labels = $count = $premium = [];
        $ccy = $this->currency();
        // R4: one grouped query per KPI (KpiEvaluator::monthly) instead of one per KPI and month.
        $issued = $this->kpiMonthly('policies.issued', $months);
        $written = $this->kpiMonthly('premium.written', $months);
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = CarbonImmutable::now()->startOfMonth()->subMonths($i);
            $labels[] = $m->translatedFormat('M Y');
            $count[] = $issued[$m->format('Y-m')]['value'] ?? 0;
            $premium[] = (int) round(($written[$m->format('Y-m')]['by_currency'][$ccy] ?? 0) / 100);
        }

        return ['labels' => $labels, 'count' => $count, 'premium' => $premium, 'currency' => $ccy];
    }

    // ------------------------------------------------------------------ CAR-004 portfolio

    public function portfolio(): array
    {
        $active = $this->kpi('policies.active');
        $inForce = $this->policies()->where('policies.status', 'ACTIVE')->selectRaw('policies.currency as ccy, COALESCE(SUM(policies.premium_minor),0) as total')->groupBy('policies.currency')->pluck('total', 'ccy')->map(fn ($v) => (int) $v)->all();
        $expiring = $this->kpi('policies.expiring_30d')['value'];
        $customers = (int) $this->policies()->where('policies.status', 'ACTIVE')->distinct()->count('policies.party_id');

        return [
            $this->tile('active_policies', (string) $active['value'], 'success', 'policies'),
            $this->tile('in_force_premium', self::money($inForce), 'success', null),
            $this->tile('insured_customers', (string) $customers, 'info', null),
            $this->tile('expiring_30d', (string) $expiring, $expiring > 0 ? 'warning' : 'success', 'policies'),
        ];
    }

    /** @return array<string,int> status => policies */
    public function policiesByStatus(): array
    {
        return $this->policies()->selectRaw('policies.status as s, COUNT(*) as n')->groupBy('policies.status')->orderByDesc('n')->pluck('n', 's')->map(fn ($v) => (int) $v)->all();
    }

    /** @return array<string,int> line code => active policies */
    public function activeByLine(): array
    {
        return $this->withProduct($this->policies()->where('policies.status', 'ACTIVE'))
            ->selectRaw("COALESCE(insurance_products.line_code, '—') as l, COUNT(*) as n")->groupBy('insurance_products.line_code')->orderByDesc('n')
            ->pluck('n', 'l')->map(fn ($v) => (int) $v)->all();
    }

    /** policies → proposals → quote_offers → insurance_products (left joins: a policy without an offer still counts). */
    private function withProduct(Builder $policies): Builder
    {
        return $policies->leftJoin('proposals', 'proposals.id', '=', 'policies.proposal_id')
            ->leftJoin('quote_offers', 'quote_offers.id', '=', 'proposals.quote_offer_id')
            ->leftJoin('insurance_products', 'insurance_products.id', '=', 'quote_offers.product_id');
    }

    // ------------------------------------------------------------------ CAR-005 claims performance

    public function claimsPerformance(?string $periodKey): array
    {
        $p = self::period($periodKey);
        $ccy = $this->currency();
        $reported = $this->kpi('claims.reported', $p)['value'];
        $closed = (clone $this->claims())->whereNotNull('claims.closed_at')->whereBetween('claims.closed_at', [$p['from'], $p['to']]);
        $closedCount = (int) (clone $closed)->count();
        $avgDays = (clone $closed)->selectRaw('AVG(EXTRACT(EPOCH FROM (claims.closed_at - COALESCE(claims.submitted_at, claims.created_at))) / 86400) as d')->value('d');
        $paid = $this->paidClaims($p);
        $reserve = $this->kpi('claims.outstanding_reserve')['by_currency'] ?? [];
        $written = $this->kpi('premium.written', $p)['by_currency'] ?? [];
        $lossRatio = self::ratio((int) ($paid[$ccy] ?? 0) + (int) ($reserve[$ccy] ?? 0), (int) ($written[$ccy] ?? 0));

        return [
            $this->tile('claims_reported', (string) $reported, 'info', 'claims'),
            $this->tile('claims_closed', (string) $closedCount, 'success', 'claims'),
            $this->tile('open_claims', (string) $this->kpi('claims.open')['value'], 'warning', 'claims'),
            $this->tile('claims_paid', self::money($paid), 'info', null),
            $this->tile('outstanding_reserve', self::money($reserve), 'info', 'claims'),
            $this->tile('loss_ratio', self::percent($lossRatio), $lossRatio !== null && $lossRatio > 70 ? 'danger' : 'success', null),
            $this->tile('average_days_to_close', $avgDays === null ? '—' : number_format((float) $avgDays, 1, ',', ' '), 'info', null),
        ];
    }

    /** @return array<string,int> currency => paid claim payments (minor) in the period */
    public function paidClaims(array $p): array
    {
        return PortalScope::narrowTable(DB::table('claim_payments')->join('claims', 'claims.id', '=', 'claim_payments.claim_id')
            ->where('claims.tenant_id', $this->tenant())->where('claim_payments.status', 'PAID'), 'claim_payments')
            ->whereBetween('claim_payments.paid_at', [$p['from'], $p['to']])
            ->selectRaw('claim_payments.currency as ccy, COALESCE(SUM(claim_payments.amount_minor),0) as total')->groupBy('claim_payments.currency')
            ->pluck('total', 'ccy')->map(fn ($v) => (int) $v)->all();
    }

    /** Reported vs closed claims per month. */
    public function claimsSeries(int $months = 12): array
    {
        $labels = $reported = $closed = [];
        // R4: two grouped queries for the whole window instead of two per month.
        $first = CarbonImmutable::now()->startOfMonth()->subMonths($months - 1);
        $byMonth = $this->kpiMonthly('claims.reported', $months);
        $closedByMonth = (clone $this->claims())->whereBetween('claims.closed_at', [$first, CarbonImmutable::now()->endOfMonth()])
            ->selectRaw("to_char(claims.closed_at, 'YYYY-MM') as m, COUNT(*) as n")->groupByRaw("to_char(claims.closed_at, 'YYYY-MM')")->pluck('n', 'm')->all();
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = CarbonImmutable::now()->startOfMonth()->subMonths($i);
            $labels[] = $m->translatedFormat('M Y');
            $reported[] = $byMonth[$m->format('Y-m')]['value'] ?? 0;
            $closed[] = (int) ($closedByMonth[$m->format('Y-m')] ?? 0);
        }

        return ['labels' => $labels, 'reported' => $reported, 'closed' => $closed];
    }

    /** @return array<string,int> status => claims */
    public function claimsByStatus(): array
    {
        return $this->claims()->selectRaw('claims.status as s, COUNT(*) as n')->groupBy('claims.status')->orderByDesc('n')->pluck('n', 's')->map(fn ($v) => (int) $v)->all();
    }

    // ------------------------------------------------------------------ CAR-006 broker production / CAR-012 intermediaries

    /**
     * The carrier's intermediaries with their production (policies / premium of the customers they brought) and their
     * delegated-authority agreement: the carrier workspace API itself (GET mobile/partner/carrier/partners).
     *
     * @return list<array{id:string, name:string, type:?string, status:?string, licence_number:?string, policies:int, premium_minor:int, agreement_number:?string, agreement_status:?string}>
     */
    public function intermediaries(): array
    {
        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => auth()->user());
        $json = app(PartnerCarrierWorkspaceController::class)->partners($request)->getData(true);

        return array_values((array) ($json['data'] ?? []));
    }

    public function brokerProduction(): array
    {
        $rows = $this->intermediaries();
        $active = count(array_filter($rows, fn ($r) => ($r['policies'] ?? 0) > 0));
        $premium = array_sum(array_column($rows, 'premium_minor'));
        $top = $rows[0] ?? null;

        return [
            $this->tile('intermediaries', (string) count($rows), 'info', 'intermediaries'),
            $this->tile('producing_intermediaries', (string) $active, 'success', 'intermediaries'),
            $this->tile('intermediated_premium', Money::format((int) $premium, $this->currency()), 'success', null),
            $this->tile('top_intermediary', $top ? (string) $top['name'] : '—', 'info', null),
        ];
    }

    // ------------------------------------------------------------------ CAR-007 product performance

    /** @return list<array{product:string, line:string, version:string, status:string, policies:int, premium_minor:int, claims:int, incurred_minor:int, loss_ratio:?float}> */
    public function productPerformance(?string $periodKey): array
    {
        // R4: the heaviest insurer read (sales + incurred per product over the period); the summary tiles and the table
        // both use it. Cached PRODUCT_TTL seconds per tenant, portal panel and carrier (the only inputs of its scope).
        $key = 'insurer-product-performance:'.$this->tenant().':'.(PortalScope::panel() ?? '-').':'.(PortalScope::carrierId() ?? '*').':'.(PortalScope::panel() === 'broker' ? auth()->id() : '').':'.$periodKey;

        return \Illuminate\Support\Facades\Cache::remember($key, self::PRODUCT_TTL, fn () => $this->computeProductPerformance($periodKey));
    }

    /** Seconds the product performance table is cached. */
    public const PRODUCT_TTL = 120;

    private function computeProductPerformance(?string $periodKey): array
    {
        $p = self::period($periodKey);
        $ccy = $this->currency();
        $sales = $this->withProduct($this->policies()->whereNotNull('policies.issued_at')->whereBetween('policies.issued_at', [$p['from'], $p['to']])->where('policies.currency', $ccy))
            ->whereNotNull('insurance_products.id')
            ->selectRaw('insurance_products.id as pid, COUNT(policies.id) as n, COALESCE(SUM(policies.premium_minor),0) as premium')->groupBy('insurance_products.id')->get()->keyBy('pid');
        $claims = $this->withProduct(DB::table('policies')->joinSub($this->claims()->whereBetween('claims.created_at', [$p['from'], $p['to']])
            ->select('claims.id as cid', 'claims.policy_id', 'claims.current_reserve_minor', 'claims.approved_amount_minor', 'claims.currency as cc'), 'c', 'c.policy_id', '=', 'policies.id'))
            ->whereNotNull('insurance_products.id')
            ->selectRaw("insurance_products.id as pid, COUNT(c.cid) as n, COALESCE(SUM(CASE WHEN c.cc = ? THEN COALESCE(c.approved_amount_minor, 0) + COALESCE(c.current_reserve_minor, 0) ELSE 0 END),0) as incurred", [$ccy])
            ->groupBy('insurance_products.id')->get()->keyBy('pid');
        $carrier = PortalScope::carrierId();
        $products = \App\Models\InsuranceProduct::query()
            ->when($carrier !== null, fn ($q) => $q->where('carrier_id', $carrier), fn ($q) => $q->whereIn('id', $sales->keys()->merge($claims->keys())->all()))
            ->orderBy('name')->orderByDesc('version')->limit(200)->get(['id', 'name', 'line_code', 'version', 'status']);

        return $products->map(function ($prod) use ($sales, $claims) {
            $premium = (int) ($sales[$prod->id]->premium ?? 0);
            $incurred = (int) ($claims[$prod->id]->incurred ?? 0);

            return ['product' => (string) $prod->name, 'line' => (string) $prod->line_code, 'version' => 'v'.$prod->version, 'status' => (string) $prod->status,
                'policies' => (int) ($sales[$prod->id]->n ?? 0), 'premium_minor' => $premium, 'claims' => (int) ($claims[$prod->id]->n ?? 0),
                'incurred_minor' => $incurred, 'loss_ratio' => self::ratio($incurred, $premium)];
        })->sortByDesc('premium_minor')->values()->all();
    }

    public function productSummary(?string $periodKey): array
    {
        $rows = $this->productPerformance($periodKey);
        $selling = array_filter($rows, fn ($r) => $r['policies'] > 0);
        $best = $rows[0] ?? null;
        $premium = array_sum(array_column($rows, 'premium_minor'));
        $incurred = array_sum(array_column($rows, 'incurred_minor'));

        return [
            $this->tile('products', (string) count($rows), 'info', 'insurance-products'),
            $this->tile('selling_products', (string) count($selling), 'success', 'insurance-products'),
            $this->tile('top_product', $best && $best['policies'] > 0 ? $best['product'] : '—', 'info', null),
            $this->tile('loss_ratio', self::percent(self::ratio($incurred, $premium)), 'info', null),
        ];
    }

    private function tile(string $key, string $value, string $tone, ?string $slug): array
    {
        return ['key' => $key, 'value' => $value, 'tone' => $tone, 'url' => $slug];
    }
}
