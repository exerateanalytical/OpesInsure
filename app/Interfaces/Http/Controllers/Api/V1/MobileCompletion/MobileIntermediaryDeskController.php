<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Agents\AssistedSaleService;
use App\Application\Audit\AuditWriter;
use App\Application\PartnerWorkspace\PartnerWorkspaceScope;
use App\Application\Policies\RenewalService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Partner;
use App\Models\Policy;
use App\Models\Quote;
use App\Models\RenewalCase;
use App\Models\Tenant;
use App\Models\TenantCustomer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Intermediary desk (2026-09-30), shared by the broker portal (app/broker/*) and the agent portal (app/agent/*);
 * the route's `portal` default says which one. Everything is scoped to the caller's own book
 * (PartnerWorkspaceScope::bookPartyIds → BookScope::bookOf: the whole book for a broker admin / an agent, the
 * team's or the caller's own clients for supervisors / staff).
 *
 *  - broker assisted sale  POST/GET mobile/broker/sales[/{id}], POST …/{id}/payment-request — the agent sale
 *    (AssistedSaleService) with channel BROKER under the broker's own partner: real rating, the client's
 *    application, the client's own terms acceptance, the real mobile-money request;
 *  - renewal desk  GET mobile/{broker|agent}/renewals/{policy}, POST …/requote (RenewalService::createQuote on the
 *    policy's current version, then the re-quote becomes the seller's assisted sale: send offer → client terms →
 *    payment → the normal issuance pipeline completes the case), POST …/decline (client decision, case DECLINED);
 *  - claimable policies  GET mobile/broker/claimable-policies?q=&customer_id=&policy_id=&page= (searchable, paged).
 */
final class MobileIntermediaryDeskController
{
    public function __construct(private PartnerWorkspaceScope $scope, private AuditWriter $audit) {}

    // ------------------------------------------------------------------ broker assisted sale

    public function createSale(Request $request, AssistedSaleService $sales): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => 'required|uuid', 'product' => 'required|string|max:32', 'payment_phone_e164' => ['required', 'regex:/^\+[1-9]\d{7,14}$/'],
            'provider' => 'nullable|in:'.implode(',', AssistedSaleService::PROVIDERS), 'risk_facts' => 'required|array|min:1',
        ]);
        [$partner, $book] = $this->seller($request, 'broker', active: true);
        $customer = $this->customerInBook($data['customer_id'], $book);
        $quote = $sales->create(Tenant::findOrFail($this->tenant()), $customer, $data, $request->user(), 'BROKER', $partner->id);

        return response()->json(['data' => $sales->present($quote, $customer, $request->user())], 201);
    }

    public function sale(string $id, Request $request, AssistedSaleService $sales): JsonResponse
    {
        [$quote, $customer] = $this->brokerSale($id, $request);

        return response()->json(['data' => $sales->present($quote, $customer, $request->user())]);
    }

    public function advanceSale(string $id, Request $request, AssistedSaleService $sales): JsonResponse
    {
        $data = $request->validate([
            'offer_id' => 'nullable|uuid', 'provider' => 'nullable|in:'.implode(',', AssistedSaleService::PROVIDERS),
            'payment_phone_e164' => ['nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
        ]);
        $this->seller($request, 'broker', active: true);
        [$quote, $customer] = $this->brokerSale($id, $request);
        $sales->advance($quote, $customer, $data, $request->user());

        return response()->json(['data' => $sales->present($quote->refresh(), $customer, $request->user())]);
    }

    // ------------------------------------------------------------------ renewal desk (broker + agent)

    public function renewal(string $policy, Request $request): JsonResponse
    {
        $portal = $this->portal($request);
        [, $book] = $this->seller($request, $portal);

        return response()->json(['data' => $this->renewalOf($this->policyInBook($policy, $book), $request, $portal)]);
    }

    /**
     * Re-rates the expiring policy (opening its renewal case when the sweep has not yet) and hands the renewal
     * quote to the caller as an assisted sale. Idempotent: an already quoted case is adopted, never re-rated twice.
     */
    public function requote(string $policy, Request $request, RenewalService $renewals, AssistedSaleService $sales): JsonResponse
    {
        $portal = $this->portal($request);
        [$partner, $book] = $this->seller($request, $portal, active: true);
        $model = $this->policyInBook($policy, $book);
        $case = DB::transaction(fn () => $renewals->openFor($model, $request->user()));
        if (in_array($case->status, ['DUE', 'CONTACTED'], true)) {
            $case = $renewals->createQuote($case, $request->user());
        }
        $quote = $case->renewal_quote_id ? Quote::where('tenant_id', $this->tenant())->find($case->renewal_quote_id) : null;
        if ($quote === null || ! in_array($case->status, ['QUOTED', 'ISSUANCE_FAILED'], true)) {
            throw ValidationException::withMessages(['status' => __('wave5.renewal_not_due')]);
        }
        $customer = TenantCustomer::with('party.contacts')->where(['tenant_id' => $this->tenant(), 'party_id' => $model->party_id])->firstOrFail();
        $sales->adopt($quote, $customer, $request->user(), $portal === 'broker' ? $partner->id : null, extra: ['renewal_case_id' => $case->id, 'renewal_of_policy_id' => $model->id]);
        $this->audit->record('renewal.desk.requoted', 'renewal_case', $case->id, ['quote_id' => $quote->id, 'portal' => $portal]);

        return response()->json(['data' => $this->renewalOf($model->refresh(), $request, $portal)]);
    }

    public function declineRenewal(string $policy, Request $request, RenewalService $renewals): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:500']);
        $portal = $this->portal($request);
        [, $book] = $this->seller($request, $portal, active: true);
        $model = $this->policyInBook($policy, $book);
        $case = DB::transaction(fn () => $renewals->openFor($model, $request->user()));
        $renewals->decline($case, $data['reason'], $request->user());

        return response()->json(['data' => $this->renewalOf($model->refresh(), $request, $portal)]);
    }

    // Agent-portal entry points: one controller action per route (RouteDuplicationTest); the route's `portal`
    // default ('agent') drives the shared logic above.
    public function agentRenewal(string $policy, Request $request): JsonResponse
    {
        return $this->renewal($policy, $request);
    }

    public function agentRequote(string $policy, Request $request, RenewalService $renewals, AssistedSaleService $sales): JsonResponse
    {
        return $this->requote($policy, $request, $renewals, $sales);
    }

    public function agentDeclineRenewal(string $policy, Request $request, RenewalService $renewals): JsonResponse
    {
        return $this->declineRenewal($policy, $request, $renewals);
    }

    // ------------------------------------------------------------------ broker claimable policies

    /** Active policies of the broker's book, searchable by policy number or client name, 20 per page. */
    public function claimablePolicies(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => 'nullable|string|max:80', 'customer_id' => 'nullable|uuid', 'policy_id' => 'nullable|uuid', 'per_page' => 'nullable|integer|min:1|max:50']);
        $partner = $this->scope->broker($request->user());
        $t = $this->tenant();
        $book = app(\App\Application\Partners\BookScope::class)->bookOf($request->user(), $partner);
        $term = trim((string) ($data['q'] ?? ''));
        $page = Policy::query()
            ->join('parties', 'parties.id', '=', 'policies.party_id')
            ->leftJoin('tenant_customers as tc', fn ($j) => $j->on('tc.party_id', '=', 'policies.party_id')->where('tc.tenant_id', '=', $t))
            ->where('policies.tenant_id', $t)->whereIn('policies.party_id', $book)->where('policies.status', 'ACTIVE')
            ->when($data['customer_id'] ?? null, fn ($q, $id) => $q->where('tc.id', $id))
            ->when($data['policy_id'] ?? null, fn ($q, $id) => $q->where('policies.id', $id))
            ->when($term !== '', function ($q) use ($term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('policies.policy_number', 'ilike', $like)->orWhere('parties.display_name', 'ilike', $like));
            })
            ->orderBy('parties.display_name')->orderBy('policies.policy_number')
            ->select(['policies.id', 'policies.policy_number', 'policies.party_id', 'policies.status', 'policies.coverage_ends_at', 'parties.display_name', 'tc.id as customer_id'])
            ->paginate((int) ($data['per_page'] ?? 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (Policy $p) => [
                'id' => $p->id, 'policy_number' => $p->policy_number, 'customer_name' => $p->getAttribute('display_name') ?? 'Client', 'party_id' => $p->party_id,
                'customer_id' => $p->getAttribute('customer_id'), 'status' => $p->status, 'coverage_ends_at' => $p->coverage_ends_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    // ------------------------------------------------------------------ scoping + shapes

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    private function portal(Request $request): string
    {
        return $request->route('portal') === 'agent' ? 'agent' : 'broker';
    }

    /** @return array{0: Partner, 1: list<string>} the caller's own partner and the party ids of the book they may see */
    private function seller(Request $request, string $portal, bool $active = false): array
    {
        $user = $request->user();
        $partner = $portal === 'agent' ? ($active ? $this->scope->activeAgent($user) : $this->scope->agent($user)) : $this->scope->broker($user);
        if ($partner === null) {
            throw new AuthorizationException;
        }
        if ($active && $portal === 'broker' && $partner->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['partner' => [__('wave12.agent_not_active')]]);
        }

        return [$partner, $this->scope->bookPartyIds($user, $partner)];
    }

    private function customerInBook(string $customerId, array $book): TenantCustomer
    {
        return TenantCustomer::with('party.contacts')->where('tenant_id', $this->tenant())->whereIn('party_id', $book)->findOrFail($customerId);
    }

    private function policyInBook(string $policyId, array $book): Policy
    {
        return Policy::with('party')->where('tenant_id', $this->tenant())->whereIn('party_id', $book)->findOrFail($policyId);
    }

    /** @return array{0: Quote, 1: TenantCustomer} a broker assisted sale of the caller's partner, client still in the caller's book (404 otherwise) */
    private function brokerSale(string $id, Request $request): array
    {
        [$partner, $book] = $this->seller($request, 'broker');
        $quote = Quote::where('tenant_id', $this->tenant())->where('comparison_context->seller_partner_id', $partner->id)->whereIn('party_id', $book)->findOrFail($id);
        $customer = TenantCustomer::with('party.contacts')->where(['tenant_id' => $this->tenant(), 'party_id' => $quote->party_id])->firstOrFail();

        return [$quote, $customer];
    }

    private function renewalOf(Policy $policy, Request $request, string $portal): array
    {
        $t = $this->tenant();
        $case = RenewalCase::where('policy_id', $policy->id)->orderByDesc('created_at')->first();
        $quote = $case?->renewal_quote_id ? Quote::find($case->renewal_quote_id) : null;
        $ctx = $quote?->comparison_context ?? [];
        $own = $quote !== null && ($ctx['assisted_sale'] ?? false) && ($portal === 'broker'
            ? ($ctx['seller_partner_id'] ?? null) === $this->scope->broker($request->user())?->id
            : ($ctx['agent_user_id'] ?? null) === $request->user()->id);
        $successor = $case?->successor_policy_id ? Policy::find($case->successor_policy_id) : Policy::where('previous_policy_id', $policy->id)->first();
        $status = $successor !== null ? 'RENEWED' : ($case?->status ?? 'NOT_OPENED');
        $manage = $request->user()->hasPermission($portal === 'broker' ? 'broker.renewals.manage' : 'agent.clients.manage');
        $actions = [];
        if ($manage && $successor === null) {
            if (in_array($status, ['NOT_OPENED', 'DUE', 'CONTACTED'], true) || (in_array($status, ['QUOTED', 'ISSUANCE_FAILED'], true) && ! $own)) {
                $actions[] = 'REQUOTE';
            }
            if (in_array($status, ['NOT_OPENED', 'DUE', 'CONTACTED', 'QUOTED'], true)) {
                $actions[] = 'DECLINE';
            }
        }
        $offer = $quote?->offers()->whereIn('status', ['OFFERED', 'ACCEPTED'])->orderBy('total_minor')->first();

        return [
            'id' => $policy->id, 'policy_id' => $policy->id, 'policy_number' => $policy->policy_number,
            'customer_id' => TenantCustomer::where(['tenant_id' => $t, 'party_id' => $policy->party_id])->value('id'), 'customer_name' => $policy->party?->display_name ?? 'Client',
            'expires_at' => $policy->coverage_ends_at?->toIso8601String(),
            'days_remaining' => $policy->coverage_ends_at ? (int) now()->startOfDay()->diffInDays($policy->coverage_ends_at, false) : null,
            'premium_minor' => (int) $policy->premium_minor,
            // NOT_OPENED | DUE | CONTACTED | QUOTED | ISSUANCE_FAILED | RENEWED | LAPSED | DECLINED
            'status' => $status, 'case_id' => $case?->id, 'window_days' => $case?->window_days, 'closed_reason' => $case?->closed_reason,
            'renewal_quote_id' => $quote?->id, 'renewal_quote_number' => $quote?->quote_number, 'renewal_premium_minor' => $offer ? (int) $offer->total_minor : null,
            // The re-quote as the caller's assisted sale (GET mobile/{portal}/sales/{sale_id}); null until re-quoted by this seller.
            'sale_id' => $own ? $quote->id : null,
            'successor_policy_id' => $successor?->id, 'successor_policy_number' => $successor?->policy_number,
            'allowed_actions' => $actions,
            'events' => $case ? DB::table('renewal_case_events')->where('renewal_case_id', $case->id)->orderByDesc('occurred_at')->limit(20)->get()
                ->map(fn ($e) => ['action' => $e->action, 'from_status' => $e->from_status, 'to_status' => $e->to_status, 'window_days' => $e->window_days, 'occurred_at' => \Carbon\Carbon::parse($e->occurred_at)->toIso8601String()])->values()->all() : [],
        ];
    }
}
